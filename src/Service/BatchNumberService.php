<?php
/**
 * @BABOK Related: FR-SN-001-004
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Contracts\BatchRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\BatchAllocation;
use ksfraser\FrontAccounting\SerialNumber\Dto\BatchNumberDto;

/**
 * Batch number handling with FEFO (first-expired, first-out) allocation.
 *
 * Quantities are floats, not ints: weighed goods (produce, bulk) are tracked in
 * fractional units and an int column silently truncates the discrepancy away.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Service
 * @since 1.0.0
 */
class BatchNumberService
{
    /** @var BatchRepositoryInterface */
    private $repo;

    /**
     * @param BatchRepositoryInterface $repo
     */
    public function __construct(BatchRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Record a received batch.
     *
     * @param string $batchNo
     * @param string $itemCode
     * @param float  $qty
     * @param array  $attributes Optional overrides (batchDate, expiryDate,
     *                           supplierRef, locationCode, notes).
     * @return BatchNumberDto
     * @throws \InvalidArgumentException On a non-positive quantity.
     */
    public function register(string $batchNo, string $itemCode, float $qty, array $attributes = array()): BatchNumberDto
    {
        $batchNo = trim($batchNo);

        if ($batchNo === '') {
            throw new \InvalidArgumentException('Batch number must not be empty');
        }

        if ($itemCode === '') {
            throw new \InvalidArgumentException('Item code must not be empty for batch ' . $batchNo);
        }

        if ($qty <= 0) {
            throw new \InvalidArgumentException('Batch quantity must be positive, got ' . $qty);
        }

        $batch = new BatchNumberDto($batchNo, $itemCode);
        $batch->qty = $qty;

        foreach (array('batchDate', 'expiryDate', 'supplierRef', 'locationCode', 'notes') as $field) {
            if (array_key_exists($field, $attributes)) {
                $batch->{$field} = $attributes[$field];
            }
        }

        $batch->id = $this->repo->insert($batch);

        return $batch;
    }

    /**
     * Split a requested quantity across batches, consuming the earliest
     * expiries first and ignoring expired stock.
     *
     * Batches with no expiry date are consumed last: they never expire, so they
     * are the correct fallback once every dated batch is gone. That ordering is
     * the repository's contract (see BatchRepositoryInterface::findFefoCandidates).
     *
     * @param string $itemCode
     * @param float  $qty
     * @param string $onDate 'Y-m-d'
     * @return BatchAllocation[] May be fewer than requested if stock is short.
     */
    public function allocateFefo(string $itemCode, float $qty, string $onDate): array
    {
        if ($qty <= 0) {
            return array();
        }

        $allocations = array();
        $remaining = $qty;

        foreach ($this->repo->findFefoCandidates($itemCode, $onDate) as $batch) {
            if ($remaining <= 0) {
                break;
            }

            if ($batch->qty <= 0) {
                continue;
            }

            // Guard against a caller passing a date that makes a batch look
            // expired even though the repository believed it usable.
            if ($batch->isExpiredOn($onDate)) {
                continue;
            }

            $take = ($batch->qty < $remaining) ? $batch->qty : $remaining;

            if ($take <= 0) {
                continue;
            }

            $this->repo->decrementQty($batch->id, $take);

            $allocations[] = new BatchAllocation($batch->id, $batch->batchNo, $take, $batch->expiryDate);
            $remaining -= $take;
        }

        return $allocations;
    }

    /**
     * Total allocation was short of the request.
     *
     * Callers need this because a partial allocation is a decision (allow the
     * sale short, or block it), not something to discover later.
     *
     * @param BatchAllocation[] $allocations
     * @param float             $requested
     * @return float Shortfall quantity.
     */
    public function shortfall(array $allocations, float $requested): float
    {
        $allocated = 0.0;

        foreach ($allocations as $allocation) {
            $allocated += $allocation->qty;
        }

        $short = $requested - $allocated;

        return $short > 0.0000001 ? round($short, 6) : 0.0;
    }

    /**
     * @param string $batchNo
     * @param string $itemCode
     * @return BatchNumberDto|null
     */
    public function find(string $batchNo, string $itemCode): ?BatchNumberDto
    {
        return $this->repo->find($batchNo, $itemCode);
    }

    /**
     * @param string      $itemCode
     * @param string|null $status
     * @return BatchNumberDto[]
     */
    public function listByItem(string $itemCode, ?string $status = null): array
    {
        return $this->repo->findByItem($itemCode, $status);
    }

    /**
     * Write off expired stock, returning how much was written off.
     *
     * @param string $itemCode
     * @param string $onDate 'Y-m-d'
     * @return float Total quantity expired.
     */
    public function writeOffExpired(string $itemCode, string $onDate): float
    {
        $writtenOff = 0.0;

        foreach ($this->repo->findByItem($itemCode, 'active') as $batch) {
            if (!$batch->isExpiredOn($onDate) || $batch->qty <= 0) {
                continue;
            }

            $batch->status = 'expired';
            $this->repo->update($batch);

            $writtenOff += $batch->qty;
        }

        return round($writtenOff, 6);
    }
}