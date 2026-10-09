<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Fakes;

use ksfraser\FrontAccounting\SerialNumber\Contracts\SerialRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialMoveDto;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;

/**
 * In-memory serial repository for unit tests.
 *
 * Services take a repository interface precisely so the lifecycle rules can be
 * verified without a database. DTOs are cloned on the way in and out so a test
 * cannot accidentally mutate stored state through a shared reference.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Fakes
 * @since 1.0.0
 */
class InMemorySerialRepository implements SerialRepositoryInterface
{
    /** @var SerialNumberDto[] keyed by serial_no */
    private $rows = array();

    /** @var SerialMoveDto[] */
    private $moves = array();

    /** @var int */
    private $nextId = 1;

    /**
     * @inheritDoc
     */
    public function insert(SerialNumberDto $serial): int
    {
        $id = $this->nextId++;
        $serial->id = $id;
        $this->rows[$serial->serialNo] = clone $serial;

        return $id;
    }

    /**
     * @inheritDoc
     */
    public function update(SerialNumberDto $serial): void
    {
        $this->rows[$serial->serialNo] = clone $serial;
    }

    /**
     * @inheritDoc
     */
    public function findBySerialNo(string $serialNo): ?SerialNumberDto
    {
        return isset($this->rows[$serialNo]) ? clone $this->rows[$serialNo] : null;
    }

    /**
     * @inheritDoc
     */
    public function findByItem(string $itemCode, ?string $status = null): array
    {
        $out = array();

        foreach ($this->rows as $serial) {
            if ($serial->itemCode !== $itemCode) {
                continue;
            }

            if ($status !== null && $status !== '' && $serial->status !== $status) {
                continue;
            }

            $out[] = clone $serial;
        }

        usort($out, function (SerialNumberDto $a, SerialNumberDto $b) {
            return strcmp($a->serialNo, $b->serialNo);
        });

        return $out;
    }

    /**
     * @inheritDoc
     */
    public function findByLocation(string $locCode, ?string $status = null): array
    {
        $out = array();

        foreach ($this->rows as $serial) {
            if ($serial->locCode !== $locCode) {
                continue;
            }

            if ($status !== null && $status !== '' && $serial->status !== $status) {
                continue;
            }

            $out[] = clone $serial;
        }

        return $out;
    }

    /**
     * @inheritDoc
     */
    public function findByFace(string $locCode, int $aisleId, int $shelfId, int $binId): array
    {
        $out = array();

        foreach ($this->rows as $serial) {
            // Every level must match: the warehouse ids are parent-scoped, so a
            // bin number alone would match units on unrelated shelves.
            if ($serial->locCode === $locCode
                && $serial->aisleId === $aisleId
                && $serial->shelfId === $shelfId
                && $serial->binId === $binId) {
                $out[] = clone $serial;
            }
        }

        return $out;
    }

    /**
     * @inheritDoc
     */
    public function appendMove(SerialMoveDto $move): void
    {
        $move->id = count($this->moves) + 1;
        $this->moves[] = clone $move;
    }

    /**
     * @inheritDoc
     */
    public function movesFor(string $serialNo): array
    {
        $out = array();

        foreach ($this->moves as $move) {
            if ($move->serialNo === $serialNo) {
                $out[] = clone $move;
            }
        }

        return array_reverse($out);
    }

    /**
     * @inheritDoc
     */
    public function exists(string $serialNo): bool
    {
        return isset($this->rows[$serialNo]);
    }

    /**
     * Seed a serial directly, bypassing the service (for edge-case setup).
     *
     * @param SerialNumberDto $serial
     * @return void
     */
    public function seed(SerialNumberDto $serial): void
    {
        $this->insert($serial);
    }

    /**
     * Convenience seeding for a unit that is simply on a shelf.
     *
     * @param string $serialNo
     * @param string $itemCode
     * @param string $status   One of the SerialNumberDto::STATUS_* constants.
     * @param bool   $shelved  Give it a complete pick face.
     * @return void
     */
    public function seedWithStatus(
        string $serialNo,
        string $itemCode,
        string $status = SerialNumberDto::STATUS_AVAILABLE,
        bool $shelved = true
    ): void {
        $serial = new SerialNumberDto($serialNo, $itemCode);
        $serial->status = $status;

        if ($shelved) {
            $serial->locCode = 'MAIN';
            $serial->aisleId = 4;
            $serial->shelfId = 2;
            $serial->binId = 3;
        }

        $this->insert($serial);
    }
}