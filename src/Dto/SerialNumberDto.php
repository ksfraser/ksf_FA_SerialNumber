<?php
/**
 * @BABOK Related: FR-SN-001-001, FR-SN-003-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

/**
 * A serialised unit: identity, pick face, lifecycle state and warranty facts.
 *
 * Ownership is deliberately NOT here -- it lives in the append-only
 * 0_ksf_serial_ownership xref (FR-SN-002-001) because it has history, while this
 * row describes the unit's current state.
 *
 * PHP 7.3 compatible: properties are untyped and every one carries a default.
 * A typed property without a default is uninitialised, and reading it throws
 * "must not be accessed before initialization" -- which makes toArray() on a
 * bare `new SerialNumberDto()` a fatal. See AGENTS_ARCH.md §1.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Dto
 * @since 1.0.0
 */
class SerialNumberDto
{
    /** Lifecycle states. */
    const STATUS_AVAILABLE = 'available';
    const STATUS_RESERVED = 'reserved';
    const STATUS_INSTALLED = 'installed';
    const STATUS_RETURNED = 'returned';
    const STATUS_RETIRED = 'retired';

    /** @var int Row id; 0 until inserted. */
    public $id = 0;

    /** @var string Unique serial number. */
    public $serialNo = '';

    /** @var string FA stock item code. */
    public $itemCode = '';

    /** @var string One of the STATUS_* constants. */
    public $status = self::STATUS_AVAILABLE;

    /** @var string|null FA location code (0_locations.loc_code). */
    public $locCode = null;

    /**
     * @var int|null Warehouse aisle index. Location-scoped, not globally unique.
     *
     * Part of the full pick-face key -- see FR-SN-003-001. A bare shelf id is
     * ambiguous: shelf 2 of aisle 4 and shelf 2 of aisle 9 are different shelves,
     * because the warehouse keys are meaningful indices scoped by parent.
     */
    public $aisleId = null;

    /** @var int|null Warehouse shelf index, scoped by (loc_code, aisle_id). */
    public $shelfId = null;

    /**
     * @var int|null Warehouse bin index -- THE PICK FACE.
     *
     * The bin is the compartment a picker reaches into; the shelf is the rack it
     * sits on. Resolving a serial to a shelf but not a bin leaves "which box is it
     * in" unanswerable. See FR-SN-003-001.
     */
    public $binId = null;

    /** @var string|null Parent batch number. */
    public $batchNo = null;

    /** @var string|null Supplier reference. */
    public $supplierRef = null;

    /** @var string|null 'Y-m-d'. */
    public $purchaseDate = null;

    /** @var float|null Cost in $currency. */
    public $purchaseCost = null;

    /** @var string|null ISO currency code. */
    public $currency = null;

    /** @var string|null 'Y-m-d'; drives the warranty clock. */
    public $installedDate = null;

    /** @var string|null 'Y-m-d'; null means no warranty recorded. */
    public $warrantyEnd = null;

    /** @var string Free text. */
    public $notes = '';

    /**
     * @param string $serialNo
     * @param string $itemCode
     */
    public function __construct(string $serialNo = '', string $itemCode = '')
    {
        $this->serialNo = $serialNo;
        $this->itemCode = $itemCode;
    }

    /**
     * The full warehouse pick-face key, or null when the unit is not shelved.
     *
     * This is the only safe way to address a serial's position, because the
     * warehouse's ids are meaningful and parent-scoped. A partial key -- shelf
     * without aisle, say -- is ambiguous and must not be persisted or joined on.
     *
     * @return array{loc_code:string,aisle_id:int,shelf_id:int,bin_id:int}|null
     */
    public function pickFace(): ?array
    {
        if ($this->locCode === null || $this->locCode === ''
            || $this->aisleId === null || $this->shelfId === null || $this->binId === null) {
            return null;
        }

        return array(
            'loc_code' => $this->locCode,
            'aisle_id' => (int)$this->aisleId,
            'shelf_id' => (int)$this->shelfId,
            'bin_id'   => (int)$this->binId,
        );
    }

    /**
     * Is this unit on a real, resolvable pick face?
     *
     * Distinguishes "at a location" from "on a specific bin". Goods-in lands on
     * the reserved UNASSIGNED face, which IS a real bin, so both are true there.
     *
     * @return bool
     */
    public function isShelved(): bool
    {
        return $this->pickFace() !== null;
    }

    /**
     * @return bool True when the unit is still sellable stock in FA.
     */
    public function isOnHand(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /**
     * @return bool True once the unit has been sold and installed.
     */
    public function isWarrantyRunning(): bool
    {
        return $this->status === self::STATUS_INSTALLED;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'             => $this->id,
            'serial_no'      => $this->serialNo,
            'item_code'      => $this->itemCode,
            'status'         => $this->status,
            'loc_code'       => $this->locCode,
            'aisle_id'       => $this->aisleId,
            'shelf_id'       => $this->shelfId,
            'bin_id'         => $this->binId,
            'batch_no'       => $this->batchNo,
            'supplier_ref'   => $this->supplierRef,
            'purchase_date'  => $this->purchaseDate,
            'purchase_cost'  => $this->purchaseCost,
            'currency'       => $this->currency,
            'installed_date' => $this->installedDate,
            'warranty_end'   => $this->warrantyEnd,
            'notes'          => $this->notes,
        );
    }
}