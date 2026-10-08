<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Contracts;

use ksfraser\FrontAccounting\SerialNumber\Dto\BatchAllocation;
use ksfraser\FrontAccounting\SerialNumber\Dto\BatchNumberDto;

/**
 * Persistence contract for batch numbers.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Contracts
 * @since 1.0.0
 */
interface BatchRepositoryInterface
{
    /**
     * @param BatchNumberDto $batch
     * @return int Inserted row id.
     */
    public function insert(BatchNumberDto $batch): int;

    /**
     * @param BatchNumberDto $batch Must carry the row id.
     * @return void
     */
    public function update(BatchNumberDto $batch): void;

    /**
     * @param string $batchNo
     * @param string $itemCode
     * @return BatchNumberDto|null
     */
    public function find(string $batchNo, string $itemCode): ?BatchNumberDto;

    /**
     * Every batch for an item, expired ones included.
     *
     * @param string      $itemCode
     * @param string|null $status
     * @return BatchNumberDto[]
     */
    public function findByItem(string $itemCode, ?string $status = null): array;

    /**
     * Decrement a batch's quantity.
     *
     * @param int   $id
     * @param float $qty Quantity to subtract. May be fractional for weighed goods.
     * @return void
     */
    public function decrementQty(int $id, float $qty): void;

    /**
     * Set a batch's absolute quantity.
     *
     * @param int   $id
     * @param float $qty
     * @return void
     */
    public function setQty(int $id, float $qty): void;

    /**
     * Candidate batches for issue against an item, for FEFO allocation.
     *
     * Implementations must return rows with qty > 0 that are not expired as at
     * $onDate, ordered by expiry ascending so the caller can consume them in
     * order. Batches with a NULL expiry sort last: they never expire, so they
     * are the correct fallback once all dated stock is gone.
     *
     * @param string $itemCode
     * @param string $onDate 'Y-m-d'
     * @return BatchNumberDto[]
     */
    public function findFefoCandidates(string $itemCode, string $onDate): array;
}