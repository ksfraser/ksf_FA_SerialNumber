<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Fakes;

use ksfraser\FrontAccounting\SerialNumber\Contracts\BatchRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\BatchNumberDto;

/**
 * In-memory batch repository for unit tests.
 *
 * findFefoCandidates() mirrors the FA adapter's ordering: active, qty > 0, not
 * expired as at $onDate, earliest expiry first, and undated batches LAST
 * (MySQL sorts NULL first ascending, so the adapter uses an explicit CASE).
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Fakes
 * @since 1.0.0
 */
class InMemoryBatchRepository implements BatchRepositoryInterface
{
    /** @var BatchNumberDto[] keyed by batch_no . '|' . item_code */
    private $rows = array();

    /** @var int */
    private $nextId = 1;

    /**
     * @inheritDoc
     */
    public function insert(BatchNumberDto $batch): int
    {
        $id = $this->nextId++;
        $batch->id = $id;
        $this->key($batch->batchNo, $batch->itemCode);
        $this->rows[$this->key($batch->batchNo, $batch->itemCode)] = clone $batch;

        return $id;
    }

    /**
     * @inheritDoc
     */
    public function update(BatchNumberDto $batch): void
    {
        $this->rows[$this->key($batch->batchNo, $batch->itemCode)] = clone $batch;
    }

    /**
     * @inheritDoc
     */
    public function find(string $batchNo, string $itemCode): ?BatchNumberDto
    {
        $key = $this->key($batchNo, $itemCode);

        return isset($this->rows[$key]) ? clone $this->rows[$key] : null;
    }

    /**
     * @inheritDoc
     */
    public function findByItem(string $itemCode, ?string $status = null): array
    {
        $out = array();

        foreach ($this->rows as $batch) {
            if ($batch->itemCode !== $itemCode) {
                continue;
            }

            if ($status !== null && $status !== '' && $batch->status !== $status) {
                continue;
            }

            $out[] = clone $batch;
        }

        usort($out, function (BatchNumberDto $a, BatchNumberDto $b) {
            return strcmp($a->batchNo, $b->batchNo);
        });

        return $out;
    }

    /**
     * @inheritDoc
     */
    public function decrementQty(int $id, float $qty): void
    {
        foreach ($this->rows as $key => $batch) {
            if ($batch->id === $id) {
                $batch->qty -= $qty;
                $this->rows[$key] = $batch;
                return;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function setQty(int $id, float $qty): void
    {
        foreach ($this->rows as $key => $batch) {
            if ($batch->id === $id) {
                $batch->qty = $qty;
                $this->rows[$key] = $batch;
                return;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function findFefoCandidates(string $itemCode, string $onDate): array
    {
        $candidates = array();

        foreach ($this->rows as $batch) {
            if ($batch->itemCode !== $itemCode || $batch->status !== 'active' || $batch->qty <= 0) {
                continue;
            }

            if ($batch->isExpiredOn($onDate)) {
                continue;
            }

            $candidates[] = clone $batch;
        }

        usort($candidates, function (BatchNumberDto $a, BatchNumberDto $b) {
            // Undated batches sort LAST: they never expire.
            $aNull = ($a->expiryDate === null || $a->expiryDate === '') ? 1 : 0;
            $bNull = ($b->expiryDate === null || $b->expiryDate === '') ? 1 : 0;

            if ($aNull !== $bNull) {
                return $aNull - $bNull;
            }

            if ($aNull === 1) {
                return strcmp($a->batchNo, $b->batchNo);
            }

            $cmp = strcmp($a->expiryDate, $b->expiryDate);

            return $cmp !== 0 ? $cmp : strcmp($a->batchNo, $b->batchNo);
        });

        return $candidates;
    }

    /**
     * @param string $batchNo
     * @param string $itemCode
     * @return string
     */
    private function key(string $batchNo, string $itemCode): string
    {
        return $batchNo . '|' . $itemCode;
    }
}