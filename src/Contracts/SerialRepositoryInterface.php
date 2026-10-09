<?php
/**
 * @BABOK Related: FR-SN-001-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Contracts;

use ksfraser\FrontAccounting\SerialNumber\Dto\SerialMoveDto;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;

/**
 * Persistence contract for serial numbers.
 *
 * The FA adapter implements this with native db_* calls; unit tests substitute
 * an in-memory double. Nothing in Service/ may touch SQL directly -- that keeps
 * the lifecycle rules testable without a database, which is why they can be
 * verified exhaustively.
 *
 * All dates are 'Y-m-d' strings. Passing a DateTime is a programming error:
 * FA's date2sql() calls trim() and would fatal.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Contracts
 * @since 1.0.0
 */
interface SerialRepositoryInterface
{
    /**
     * Insert a new serial. The serial number must be unique.
     *
     * @param SerialNumberDto $serial
     * @return int Inserted row id.
     */
    public function insert(SerialNumberDto $serial): int;

    /**
     * Persist changes to an existing serial.
     *
     * @param SerialNumberDto $serial Must carry the row id.
     * @return void
     */
    public function update(SerialNumberDto $serial): void;

    /**
     * @param string $serialNo
     * @return SerialNumberDto|null Null when not found.
     */
    public function findBySerialNo(string $serialNo): ?SerialNumberDto;

    /**
     * All serials for an item, optionally filtered by status.
     *
     * @param string      $itemCode
     * @param string|null $status Null for any status.
     * @return SerialNumberDto[]
     */
    public function findByItem(string $itemCode, ?string $status = null): array;

    /**
     * All serials at an FA location.
     *
     * @param string      $locCode
     * @param string|null $status
     * @return SerialNumberDto[]
     */
    public function findByLocation(string $locCode, ?string $status = null): array;

    /**
     * Serials on one warehouse pick face.
     *
     * The full scoped key is required, not just a shelf: the warehouse's ids are
     * meaningful indices scoped by parent, so shelf 2 of aisle 4 and shelf 2 of
     * aisle 9 are different shelves and a bare shelf id is ambiguous. See
     * FR-SN-003-001.
     *
     * @param string $locCode
     * @param int    $aisleId
     * @param int    $shelfId
     * @param int    $binId
     * @return SerialNumberDto[]
     */
    public function findByFace(string $locCode, int $aisleId, int $shelfId, int $binId): array;

    /**
     * Append a location-change record to the audit trail.
     *
     * @param SerialMoveDto $move
     * @return void
     */
    public function appendMove(SerialMoveDto $move): void;

    /**
     * Location history for a serial, newest first.
     *
     * @param string $serialNo
     * @return SerialMoveDto[]
     */
    public function movesFor(string $serialNo): array;

    /**
     * @param string $serialNo
     * @return bool
     */
    public function exists(string $serialNo): bool;
}