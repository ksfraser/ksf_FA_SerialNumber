<?php
/**
 * @BABOK Related: FR-SN-001-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

/**
 * A serialised unit: identity, location, lifecycle state and warranty facts.
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

    /** @var int|null Warehouse shelf id. Opaque here; warehouse resolves it. */
    public $shelfId = null;

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

    /** @var string|null Customer identifier once sold. */
    public $soldTo = null;

    /** @var string|null 'Y-m-d'. */
    public $soldDate = null;

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
            'shelf_id'       => $this->shelfId,
            'batch_no'       => $this->batchNo,
            'supplier_ref'   => $this->supplierRef,
            'purchase_date'  => $this->purchaseDate,
            'purchase_cost'  => $this->purchaseCost,
            'currency'       => $this->currency,
            'sold_to'        => $this->soldTo,
            'sold_date'      => $this->soldDate,
            'installed_date' => $this->installedDate,
            'warranty_end'   => $this->warrantyEnd,
            'notes'          => $this->notes,
        );
    }
}