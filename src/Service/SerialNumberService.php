<?php
/**
 * @BABOK Related: FR-SN-001-001, FR-SN-001-002, FR-SN-002-001, FR-SN-003-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Contracts\OwnershipRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Contracts\SerialRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\OwnershipDto;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialMoveDto;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;
use ksfraser\FrontAccounting\SerialNumber\Exception\DuplicateSerialException;
use ksfraser\FrontAccounting\SerialNumber\Exception\InvalidSerialStateException;
use ksfraser\FrontAccounting\SerialNumber\Exception\SerialNotFoundException;

/**
 * Serial number lifecycle.
 *
 * All state changes go through this class, and every one of them writes a row to
 * the location log. That is what makes "where is this unit now?" answerable and
 * what lets serial position be reconciled against aggregate FA stock.
 *
 * The allowed transitions are deliberate and closed. A unit that has been sold
 * and installed cannot go back to 'available' by a bare status write -- it must
 * come back through returnSerial(), which closes the ownership period and
 * restarts the warranty clock on the new install.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Service
 * @since 1.0.0
 */
class SerialNumberService
{
    /**
     * Legal state transitions. Key = current state, value = allowed next states.
     *
     * @var array<string,string[]>
     */
    private const TRANSITIONS = array(
        // A newly received unit can be sold, reserved or written off.
        SerialNumberDto::STATUS_AVAILABLE => array(
            SerialNumberDto::STATUS_RESERVED,
            SerialNumberDto::STATUS_INSTALLED,
            SerialNumberDto::STATUS_RETIRED,
        ),
        // A reserved unit goes back to the shelf or on to the customer.
        SerialNumberDto::STATUS_RESERVED => array(
            SerialNumberDto::STATUS_AVAILABLE,
            SerialNumberDto::STATUS_INSTALLED,
            SerialNumberDto::STATUS_RETIRED,
        ),
        // Installed units leave via return or failure. Not back to stock.
        SerialNumberDto::STATUS_INSTALLED => array(
            SerialNumberDto::STATUS_RETURNED,
            SerialNumberDto::STATUS_RETIRED,
        ),
        // Returned stock re-enters the shelf and the warranty clock restarts.
        SerialNumberDto::STATUS_RETURNED => array(
            SerialNumberDto::STATUS_AVAILABLE,
            SerialNumberDto::STATUS_RETIRED,
        ),
        // Terminal.
        SerialNumberDto::STATUS_RETIRED => array(),
    );

    /** @var SerialRepositoryInterface */
    private $repo;

    /**
     * @var OwnershipRepositoryInterface|null
     *
     * Optional so the module keeps working before the ownership xref is wired in;
     * every method that needs it refuses loudly rather than silently skipping.
     */
    private $owners;

    /** @var string Clock injection point; 'Y-m-d H:i:s'. */
    private $now;

    /**
     * @param SerialRepositoryInterface $repo
     * @param string|null $now Fixed timestamp for deterministic tests.
     */
    public function __construct(
        SerialRepositoryInterface $repo,
        ?string $now = null,
        ?OwnershipRepositoryInterface $owners = null
    ) {
        $this->repo = $repo;
        $this->now = $now;
        $this->owners = $owners;
    }

    /**
     * The ownership history repository.
     *
     * @return OwnershipRepositoryInterface
     * @throws InvalidSerialStateException When it was not supplied. Refusing beats
     *         silently recording no ownership, which is how the old sold_to
     *         column came to be the only record of who owned a unit.
     */
    private function owners(): OwnershipRepositoryInterface
    {
        if ($this->owners === null) {
            throw new InvalidSerialStateException(
                'No ownership repository configured; ownership cannot be recorded'
            );
        }

        return $this->owners;
    }

    /**
     * Record a newly received unit.
     *
     * @param string $serialNo
     * @param string $itemCode
     * @param array  $attributes Optional overrides (locCode, aisleId, shelfId,
     *                           binId, batchNo, purchaseDate, purchaseCost,
     *                           currency, supplierRef, warrantyEnd, notes).
     * @return SerialNumberDto
     * @throws DuplicateSerialException When the serial already exists.
     */
    public function register(string $serialNo, string $itemCode, array $attributes = array()): SerialNumberDto
    {
        $serialNo = trim($serialNo);

        if ($serialNo === '') {
            throw new InvalidSerialStateException('Serial number must not be empty');
        }

        if ($itemCode === '') {
            throw new InvalidSerialStateException('Item code must not be empty for serial ' . $serialNo);
        }

        if ($this->repo->exists($serialNo)) {
            throw new DuplicateSerialException('Serial ' . $serialNo . ' already exists');
        }

        $serial = new SerialNumberDto($serialNo, $itemCode);
        $serial->status = SerialNumberDto::STATUS_AVAILABLE;

        foreach (array(
            'locCode', 'aisleId', 'shelfId', 'binId', 'batchNo', 'supplierRef',
            'purchaseDate', 'purchaseCost', 'currency', 'warrantyEnd', 'notes',
        ) as $field) {
            if (array_key_exists($field, $attributes)) {
                $serial->{$field} = $attributes[$field];
            }
        }

        $serial->id = $this->repo->insert($serial);

        // No face at all is fine -- the unit has simply not been put away yet.
        $this->assertFaceIsWholeOrAbsent($serial, $serialNo);

        if ($serial->locCode !== null) {
            $this->logMove($serial, null, 'receipt');
        }

        return $serial;
    }

    /**
     * @param string $serialNo
     * @return SerialNumberDto
     * @throws SerialNotFoundException
     */
    public function get(string $serialNo): SerialNumberDto
    {
        $serial = $this->repo->findBySerialNo($serialNo);

        if ($serial === null) {
            throw new SerialNotFoundException('Serial ' . $serialNo . ' cannot be found');
        }

        return $serial;
    }

    /**
     * Place a unit at a location (receiving it into stock, or returning it).
     *
     * The whole scoped key is required. A shelf id on its own is ambiguous, because
     * the warehouse's ids are meaningful indices scoped by parent: shelf 2 of
     * aisle 4 and shelf 2 of aisle 9 are different shelves. Goods-in passes the
     * reserved UNASSIGNED face, which is a real bin like any other.
     *
     * @param string   $serialNo
     * @param string   $locCode  FA location code.
     * @param int|null $aisleId
     * @param int|null $shelfId
     * @param int|null $binId   The pick face.
     * @param string   $reason
     * @return SerialNumberDto
     * @throws SerialNotFoundException
     * @throws InvalidSerialStateException When retired, or the face is incomplete.
     */
    public function assignLocation(
        string $serialNo,
        string $locCode,
        ?int $aisleId = null,
        ?int $shelfId = null,
        ?int $binId = null,
        string $reason = 'assign'
    ): SerialNumberDto {
        $serial = $this->get($serialNo);

        if ($serial->status === SerialNumberDto::STATUS_RETIRED) {
            throw new InvalidSerialStateException(
                'Serial ' . $serialNo . ' is retired and cannot be moved'
            );
        }

        $from = $serial->pickFace();

        $serial->locCode = $locCode;
        $serial->aisleId = $aisleId;
        $serial->shelfId = $shelfId;
        $serial->binId = $binId;

        $this->assertFaceIsWholeOrAbsent($serial, $serialNo);

        $this->repo->update($serial);
        $this->logMove($serial, $from, $reason);

        return $serial;
    }

    /**
     * The face is either wholly specified or wholly absent -- never partial.
     *
     * A PARTIAL face is ambiguous and must be refused: the warehouse's ids are
     * meaningful indices scoped by parent, so a shelf without an aisle cannot be
     * resolved to a real position. An ABSENT face is legitimate -- the unit has
     * not been put away yet, or has left the building.
     *
     * @param SerialNumberDto $serial
     * @param string          $serialNo For the message.
     * @return void
     * @throws InvalidSerialStateException When part of the key is present.
     */
    private function assertFaceIsWholeOrAbsent(SerialNumberDto $serial, string $serialNo): void
    {
        $given = 0;

        if ($serial->locCode !== null && $serial->locCode !== '') {
            $given++;
        }

        foreach (array($serial->aisleId, $serial->shelfId, $serial->binId) as $part) {
            if ($part !== null) {
                $given++;
            }
        }

        if ($given === 0 || $given === 4) {
            return;
        }

        throw new InvalidSerialStateException(
            'Serial ' . $serialNo . ' needs locCode, aisleId, shelfId and binId together; got '
            . $given . ' of 4'
        );
    }

    /**
     * Move a unit between locations.
     *
     * This records where the unit went; it does NOT move aggregate FA stock.
     * Aggregate movement is warehouse's job (it owns the holding tank) so that
     * stock and serials cannot diverge through two independent writers.
     *
     * @param string   $serialNo
     * @param string   $toLocCode
     * @param int|null $toAisleId
     * @param int|null $toShelfId
     * @param int|null $toBinId   The destination pick face.
     * @param string   $reason
     * @return SerialNumberDto
     * @throws SerialNotFoundException
     * @throws InvalidSerialStateException
     */
    public function move(
        string $serialNo,
        string $toLocCode,
        ?int $toAisleId = null,
        ?int $toShelfId = null,
        ?int $toBinId = null,
        string $reason = 'transfer'
    ): SerialNumberDto {
        return $this->assignLocation($serialNo, $toLocCode, $toAisleId, $toShelfId, $toBinId, $reason);
    }

    /**
     * Commit a unit to an order.
     *
     * @param string $serialNo
     * @return SerialNumberDto
     * @throws InvalidSerialStateException
     */
    public function reserve(string $serialNo): SerialNumberDto
    {
        return $this->transition($serialNo, SerialNumberDto::STATUS_RESERVED);
    }

    /**
     * Release a reservation back to available stock.
     *
     * @param string $serialNo
     * @return SerialNumberDto
     * @throws InvalidSerialStateException
     */
    public function unreserve(string $serialNo): SerialNumberDto
    {
        return $this->transition($serialNo, SerialNumberDto::STATUS_AVAILABLE);
    }

    /**
     * Record the sale and start the warranty clock.
     *
     * The warranty clock runs from installedDate, which is set to the sale date
     * here: a unit sitting in a warehouse for three months before install should
     * not have burned three months of cover.
     *
     * Ownership is appended to the xref, not written over a column. A resale
     * therefore closes the previous owner's period and opens a new one, and the
     * whole trail survives.
     *
     * @param string      $serialNo
     * @param string      $ownerRef  Owner id within $ownerKind.
     * @param string      $onDate    'Y-m-d'
     * @param int         $warrantyDays 0 for no warranty.
     * @param string      $ownerKind One of the OwnershipDto::KIND_* constants.
     * @return SerialNumberDto
     * @throws InvalidSerialStateException When the kind is not permitted, or the
     *         unit cannot be sold from its current state.
     */
    public function markSold(
        string $serialNo,
        string $ownerRef,
        string $onDate,
        int $warrantyDays = 0,
        string $ownerKind = OwnershipDto::KIND_DEBTOR
    ): SerialNumberDto {
        if (!OwnershipDto::isValidKind($ownerKind)) {
            throw new InvalidSerialStateException(
                'Owner kind must be one of: ' . implode(', ', OwnershipDto::kinds())
            );
        }

        if (trim($ownerRef) === '') {
            throw new InvalidSerialStateException('An owner reference is required to sell a serial');
        }

        $serial = $this->get($serialNo);

        if (!in_array(SerialNumberDto::STATUS_INSTALLED, self::TRANSITIONS[$serial->status], true)) {
            throw new InvalidSerialStateException(
                'Cannot sell serial ' . $serialNo . ' while it is ' . $serial->status
            );
        }

        $serial->status = SerialNumberDto::STATUS_INSTALLED;
        $serial->installedDate = $onDate;

        if ($warrantyDays > 0) {
            $serial->warrantyEnd = $this->addDays($onDate, $warrantyDays);
        } else {
            $serial->warrantyEnd = null;
        }

        $this->repo->update($serial);

        // Close any previous owner's period before opening this one, so a resale
        // keeps the earlier holder on record.
        $this->owners()->closeCurrent($serialNo, $onDate);

        $ownership = new OwnershipDto($serialNo, $ownerKind, trim($ownerRef), $onDate);
        $this->owners()->open($ownership);

        $this->logMove($serial, $serial->pickFace(), 'sold');

        return $serial;
    }

    /**
     * Who currently holds this unit?
     *
     * @param string $serialNo
     * @return OwnershipDto|null Null when it has never been sold.
     * @throws InvalidSerialStateException
     */
    public function currentOwner(string $serialNo): ?OwnershipDto
    {
        // Proves the serial exists, so a typo reports "no such serial" rather than
        // a silent null that reads as "never sold".
        $this->get($serialNo);

        return $this->owners()->current($serialNo);
    }

    /**
     * Full ownership history, oldest first.
     *
     * @param string $serialNo
     * @return OwnershipDto[]
     * @throws InvalidSerialStateException
     */
    public function ownershipHistory(string $serialNo): array
    {
        $this->get($serialNo);

        return $this->owners()->history($serialNo);
    }

    /**
     * Who held this unit on a given date?
     *
     * This is the question warranty entitlement actually turns on: the same unit
     * can have different owners on different dates.
     *
     * @param string $serialNo
     * @param string $onDate   'Y-m-d'
     * @return OwnershipDto|null
     * @throws InvalidSerialStateException
     */
    public function ownerAsAt(string $serialNo, string $onDate): ?OwnershipDto
    {
        $this->get($serialNo);

        return $this->owners()->ownerAsAt($serialNo, $onDate);
    }

    /**
     * Every unit currently held by one owner of a given kind.
     *
     * @param string $ownerKind One of the OwnershipDto::KIND_* constants.
     * @param string $ownerRef
     * @return OwnershipDto[]
     * @throws InvalidSerialStateException
     */
    public function unitsHeldBy(string $ownerKind, string $ownerRef): array
    {
        if (!OwnershipDto::isValidKind($ownerKind)) {
            throw new InvalidSerialStateException(
                'Owner kind must be one of: ' . implode(', ', OwnershipDto::kinds())
            );
        }

        return $this->owners()->heldBy($ownerKind, $ownerRef);
    }

    /**
     * Take a sold unit back. Clears the customer and stops the warranty clock.
     *
     * @param string $serialNo
     * @return SerialNumberDto
     * @throws InvalidSerialStateException
     */
    public function returnSerial(string $serialNo): SerialNumberDto
    {
        $serial = $this->get($serialNo);

        if ($serial->status !== SerialNumberDto::STATUS_INSTALLED) {
            throw new InvalidSerialStateException(
                'Cannot return serial ' . $serialNo . ' while it is ' . $serial->status
            );
        }

        $serial->status = SerialNumberDto::STATUS_RETURNED;
        $serial->installedDate = null;
        $serial->warrantyEnd = null;

        $this->repo->update($serial);

        // Close the ownership period rather than deleting the row: the unit went
        // back to unsold stock, and that fact belongs in the trail.
        $this->owners()->closeCurrent($serialNo, $this->today());

        $this->logMove($serial, $serial->pickFace(), 'return');

        return $serial;
    }

    /**
     * Put returned stock back on the shelf as available.
     *
     * @param string      $serialNo
     * @param string|null $locCode Defaults to the unit's current location.
     * @return SerialNumberDto
     * @throws InvalidSerialStateException
     */
    public function putBackInStock(string $serialNo, ?string $locCode = null): SerialNumberDto
    {
        $serial = $this->transition($serialNo, SerialNumberDto::STATUS_AVAILABLE);

        if ($locCode !== null && $locCode !== '' && $locCode !== $serial->locCode) {
            return $this->assignLocation(
                $serialNo,
                $locCode,
                $serial->aisleId,
                $serial->shelfId,
                $serial->binId,
                'put-back'
            );
        }

        return $serial;
    }

    /**
     * Scrapped or decommissioned. Terminal -- nothing leaves 'retired'.
     *
     * @param string $serialNo
     * @param string $reason
     * @return SerialNumberDto
     * @throws InvalidSerialStateException
     */
    public function retire(string $serialNo, string $reason = 'retired'): SerialNumberDto
    {
        $serial = $this->get($serialNo);

        if ($serial->status === SerialNumberDto::STATUS_RETIRED) {
            return $serial;
        }

        if (!in_array(SerialNumberDto::STATUS_RETIRED, self::TRANSITIONS[$serial->status], true)) {
            throw new InvalidSerialStateException(
                'Cannot retire serial ' . $serialNo . ' while it is ' . $serial->status
            );
        }

        $from = $serial->pickFace();

        $serial->status = SerialNumberDto::STATUS_RETIRED;
        $serial->locCode = null;
        $serial->aisleId = null;
        $serial->shelfId = null;
        $serial->binId = null;

        $this->repo->update($serial);
        $this->logMove($serial, $from, $reason);

        return $serial;
    }

    /**
     * @param string      $itemCode
     * @param string|null $status
     * @return SerialNumberDto[]
     */
    public function listByItem(string $itemCode, ?string $status = null): array
    {
        return $this->repo->findByItem($itemCode, $status);
    }

    /**
     * @param string      $locCode
     * @param string|null $status
     * @return SerialNumberDto[]
     */
    public function listByLocation(string $locCode, ?string $status = null): array
    {
        return $this->repo->findByLocation($locCode, $status);
    }

    /**
     * @param string $serialNo
     * @return SerialMoveDto[] Newest first.
     */
    public function history(string $serialNo): array
    {
        return $this->repo->movesFor($serialNo);
    }

    /**
     * Count units of an item at a location -- the reconciliation primitive that
     * answers "how many of these are here?" for serial-controlled stock.
     *
     * @param string $itemCode
     * @param string $locCode
     * @return int
     */
    public function countAtLocation(string $itemCode, string $locCode): int
    {
        $count = 0;

        foreach ($this->repo->findByLocation($locCode, null) as $serial) {
            if ($serial->itemCode === $itemCode && $serial->status !== SerialNumberDto::STATUS_RETIRED) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Apply a state change, enforcing the transition table.
     *
     * @param string $serialNo
     * @param string $newStatus
     * @return SerialNumberDto
     * @throws InvalidSerialStateException
     */
    private function transition(string $serialNo, string $newStatus): SerialNumberDto
    {
        $serial = $this->get($serialNo);

        $allowed = isset(self::TRANSITIONS[$serial->status])
            ? self::TRANSITIONS[$serial->status]
            : array();

        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidSerialStateException(
                'Cannot move serial ' . $serialNo . ' from ' . $serial->status . ' to ' . $newStatus
            );
        }

        $serial->status = $newStatus;
        $this->repo->update($serial);

        return $serial;
    }

    /**
     * Append to the audit trail.
     *
     * The log records the whole scoped key at both ends, so the trail can be
     * replayed to reconstruct where a unit has been.
     *
     * @param SerialNumberDto                  $serial
     * @param array{loc_code:string,aisle_id:int,shelf_id:int,bin_id:int}|null $from
     * @param string                           $reason
     * @return void
     */
    private function logMove(SerialNumberDto $serial, ?array $from, string $reason): void
    {
        $move = new SerialMoveDto();
        $move->serialNo = $serial->serialNo;
        $move->fromLocCode = $from === null ? null : $from['loc_code'];
        $move->fromAisleId = $from === null ? null : $from['aisle_id'];
        $move->fromShelfId = $from === null ? null : $from['shelf_id'];
        $move->fromBinId   = $from === null ? null : $from['bin_id'];
        $move->toLocCode = $serial->locCode;
        $move->toAisleId = $serial->aisleId;
        $move->toShelfId = $serial->shelfId;
        $move->toBinId   = $serial->binId;
        $move->reason = $reason;
        $move->movedBy = function_exists('get_current_user') ? get_current_user() : null;
        $move->movedAt = $this->now !== null ? $this->now : date('Y-m-d H:i:s');

        $this->repo->appendMove($move);
    }

    /**
     * Today, honouring the injected clock.
     *
     * Everything else in this class dates from $now when it is supplied, so
     * calling date() directly would make one code path disagree with the rest --
     * a return booked under an injected 2026-03-01 would close the ownership
     * period on the real wall-clock date.
     *
     * @return string 'Y-m-d'
     */
    private function today(): string
    {
        if ($this->now !== null) {
            return substr($this->now, 0, 10);
        }

        return date('Y-m-d');
    }

    /**
     * Add whole days to a 'Y-m-d' date.
     *
     * @param string $date
     * @param int    $days
     * @return string 'Y-m-d'
     */
    private function addDays(string $date, int $days): string
    {
        $ts = strtotime($date);
        return date('Y-m-d', strtotime('+' . $days . ' days', $ts));
    }
}