<?php
/**
 * @BABOK Related: FR-SN-002-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Contracts;

use ksfraser\FrontAccounting\SerialNumber\Dto\OwnershipDto;

/**
 * Append-only persistence contract for serial ownership history.
 *
 * Deliberately separate from SerialRepositoryInterface: the serial row answers
 * "what is this unit's state", the ownership xref answers "who has held it and
 * when". Mixing them is how a single mutable owner column came to exist in the
 * first place.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Contracts
 * @since 1.1.0
 */
interface OwnershipRepositoryInterface
{
    /**
     * Open a new ownership period, closing the previous open one.
     *
     * MUST be atomic: if a period is already open for the serial it is closed at
     * $ownedFrom in the same transaction that opens the new one. Two open rows
     * for one serial is a data-integrity failure, not a recoverable state.
     *
     * @param OwnershipDto $ownership owned_to must be null.
     * @return int Inserted row id.
     */
    public function open(OwnershipDto $ownership): int;

    /**
     * Close the currently open period for a serial.
     *
     * @param string $serialNo
     * @param string $ownedTo    'Y-m-d' -- the day ownership ended.
     * @return bool True when an open period was closed.
     */
    public function closeCurrent(string $serialNo, string $ownedTo): bool;

    /**
     * The current owner, or null when the unit has never been sold.
     *
     * @param string $serialNo
     * @return OwnershipDto|null
     */
    public function current(string $serialNo): ?OwnershipDto;

    /**
     * Full ownership history, oldest first.
     *
     * @param string $serialNo
     * @return OwnershipDto[]
     */
    public function history(string $serialNo): array;

    /**
     * The owner in possession on a given date.
     *
     * @param string $serialNo
     * @param string $onDate   'Y-m-d'
     * @return OwnershipDto|null
     */
    public function ownerAsAt(string $serialNo, string $onDate): ?OwnershipDto;

    /**
     * Every unit held by one owner of a given kind.
     *
     * @param string $ownerKind
     * @param string $ownerRef
     * @return OwnershipDto[] Current owners only.
     */
    public function heldBy(string $ownerKind, string $ownerRef): array;
}