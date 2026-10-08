<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Contracts\SerialRepositoryInterface;
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
 * come back through returnSerial(), which clears sold_to and restarts the
 * warranty clock on the new install.
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

    /** @var string Clock injection point; 'Y-m-d H:i:s'. */
    private $now;

    /**
     * @param SerialRepositoryInterface $repo
     * @param string|null $now Fixed timestamp for deterministic tests.
     */
    public function __construct(SerialRepositoryInterface $repo, ?string $now = null)
    {
        $this->repo = $repo;
        $this->now = $now;
    }

    /**
     * Record a newly received unit.
     *
     * @param string $serialNo
     * @param string $itemCode
     * @param array  $attributes Optional overrides (locCode, shelfId, batchNo,
     *                           purchaseDate, purchaseCost, currency, supplierRef,
     *                           warrantyEnd, notes).
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
            'locCode', 'shelfId', 'batchNo', 'supplierRef', 'purchaseDate',
            'purchaseCost', 'currency', 'warrantyEnd', 'notes',
        ) as $field) {
            if (array_key_exists($field, $attributes)) {
                $serial->{$field} = $attributes[$field];
            }
        }

        $serial->id = $this->repo->insert($serial);

        if ($serial->locCode !== null || $serial->shelfId !== null) {
            $this->logMove($serial, null, null, 'receipt');
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
     * @param string      $serialNo
     * @param string      $locCode  FA location code.
     * @param int|null    $shelfId  Optional warehouse shelf.
     * @param string      $reason
     * @return SerialNumberDto
     * @throws SerialNotFoundException
     * @throws InvalidSerialStateException When retired.
     */
    public function assignLocation(string $serialNo, string $locCode, ?int $shelfId = null, string $reason = 'assign'): SerialNumberDto
    {
        $serial = $this->get($serialNo);

        if ($serial->status === SerialNumberDto::STATUS_RETIRED) {
            throw new InvalidSerialStateException(
                'Serial ' . $serialNo . ' is retired and cannot be moved'
            );
        }

        $fromLoc = $serial->locCode;
        $fromShelf = $serial->shelfId;

        $serial->locCode = $locCode;
        $serial->shelfId = $shelfId;

        $this->repo->update($serial);
        $this->logMove($serial, $fromLoc, $fromShelf, $reason);

        return $serial;
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
     * @param int|null $toShelfId
     * @param string   $reason
     * @return SerialNumberDto
     * @throws SerialNotFoundException
     * @throws InvalidSerialStateException
     */
    public function move(string $serialNo, string $toLocCode, ?int $toShelfId = null, string $reason = 'transfer'): SerialNumberDto
    {
        return $this->assignLocation($serialNo, $toLocCode, $toShelfId, $reason);
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
     * The warranty clock runs from installedDate, not soldDate: a unit sitting
     * in a warehouse for three months before install should not have burned
     * three months of cover.
     *
     * @param string      $serialNo
     * @param string      $soldTo   Customer identifier.
     * @param string      $onDate   'Y-m-d'
     * @param int         $warrantyDays 0 for no warranty.
     * @return SerialNumberDto
     * @throws InvalidSerialStateException
     */
    public function markSold(string $serialNo, string $soldTo, string $onDate, int $warrantyDays = 0): SerialNumberDto
    {
        $serial = $this->get($serialNo);

        if (!in_array(SerialNumberDto::STATUS_INSTALLED, self::TRANSITIONS[$serial->status], true)) {
            throw new InvalidSerialStateException(
                'Cannot sell serial ' . $serialNo . ' while it is ' . $serial->status
            );
        }

        $serial->status = SerialNumberDto::STATUS_INSTALLED;
        $serial->soldTo = $soldTo;
        $serial->soldDate = $onDate;
        $serial->installedDate = $onDate;

        if ($warrantyDays > 0) {
            $serial->warrantyEnd = $this->addDays($onDate, $warrantyDays);
        } else {
            $serial->warrantyEnd = null;
        }

        $this->repo->update($serial);
        $this->logMove($serial, $serial->locCode, $serial->shelfId, 'sold');

        return $serial;
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
        $serial->soldTo = null;
        $serial->soldDate = null;
        $serial->installedDate = null;
        $serial->warrantyEnd = null;

        $this->repo->update($serial);
        $this->logMove($serial, $serial->locCode, $serial->shelfId, 'return');

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
            return $this->assignLocation($serialNo, $locCode, $serial->shelfId, 'put-back');
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

        $serial->status = SerialNumberDto::STATUS_RETIRED;
        $serial->locCode = null;
        $serial->shelfId = null;

        $this->repo->update($serial);
        $this->logMove($serial, null, null, $reason);

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
     * @param SerialNumberDto $serial
     * @param string|null     $fromLoc
     * @param int|null        $fromShelf
     * @param string          $reason
     * @return void
     */
    private function logMove(SerialNumberDto $serial, ?string $fromLoc, ?int $fromShelf, string $reason): void
    {
        $move = new SerialMoveDto();
        $move->serialNo = $serial->serialNo;
        $move->fromLocCode = $fromLoc;
        $move->toLocCode = $serial->locCode;
        $move->fromShelfId = $fromShelf;
        $move->toShelfId = $serial->shelfId;
        $move->reason = $reason;
        $move->movedBy = function_exists('get_current_user') ? get_current_user() : null;
        $move->movedAt = $this->now !== null ? $this->now : date('Y-m-d H:i:s');

        $this->repo->appendMove($move);
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