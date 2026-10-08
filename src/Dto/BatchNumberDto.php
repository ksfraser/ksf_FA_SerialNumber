<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

/**
 * A batch of an item, with optional expiry.
 *
 * PHP 7.3 compatible: untyped, fully defaulted properties.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Dto
 * @since 1.0.0
 */
class BatchNumberDto
{
    /** @var int Row id; 0 until inserted. */
    public $id = 0;

    /** @var string */
    public $batchNo = '';

    /** @var string */
    public $itemCode = '';

    /** @var float Quantity on hand. Fractional for weighed goods. */
    public $qty = 0.0;

    /** @var string|null 'Y-m-d'. */
    public $batchDate = null;

    /** @var string|null 'Y-m-d'; null means the batch never expires. */
    public $expiryDate = null;

    /** @var string|null */
    public $supplierRef = null;

    /** @var string|null FA location code. */
    public $locationCode = null;

    /** @var string */
    public $status = 'active';

    /** @var string */
    public $notes = '';

    /**
     * @param string $batchNo
     * @param string $itemCode
     */
    public function __construct(string $batchNo = '', string $itemCode = '')
    {
        $this->batchNo = $batchNo;
        $this->itemCode = $itemCode;
    }

    /**
     * Is this batch past its expiry as at a given date?
     *
     * A null expiry never expires. Expiry is inclusive: a batch expiring
     * 2026-01-31 is still good ON 2026-01-31 and bad on 2026-02-01, which is how
     * "use before" dates read on packaging.
     *
     * @param string $onDate 'Y-m-d'
     * @return bool
     */
    public function isExpiredOn(string $onDate): bool
    {
        if ($this->expiryDate === null || $this->expiryDate === '') {
            return false;
        }

        return $this->expiryDate < $onDate;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'             => $this->id,
            'batch_no'       => $this->batchNo,
            'item_code'      => $this->itemCode,
            'qty'            => $this->qty,
            'batch_date'     => $this->batchDate,
            'expiry_date'    => $this->expiryDate,
            'supplier_ref'   => $this->supplierRef,
            'location_code'  => $this->locationCode,
            'status'         => $this->status,
            'notes'          => $this->notes,
        );
    }
}
