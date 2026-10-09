<?php
/**
 * @BABOK Related: FR-SN-001-004
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

/**
 * One batch's contribution to an allocation request.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Dto
 * @since 1.0.0
 */
class BatchAllocation
{
    /** @var int Batch row id. */
    public $batchId = 0;

    /** @var string */
    public $batchNo = '';

    /** @var string|null 'Y-m-d'. */
    public $expiryDate = null;

    /** @var float Quantity drawn from this batch. */
    public $qty = 0.0;

    /**
     * @param int    $batchId
     * @param string $batchNo
     * @param float  $qty
     * @param string|null $expiryDate
     */
    public function __construct(int $batchId = 0, string $batchNo = '', float $qty = 0.0, ?string $expiryDate = null)
    {
        $this->batchId = $batchId;
        $this->batchNo = $batchNo;
        $this->qty = $qty;
        $this->expiryDate = $expiryDate;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'batch_id'    => $this->batchId,
            'batch_no'    => $this->batchNo,
            'qty'         => $this->qty,
            'expiry_date' => $this->expiryDate,
        );
    }
}
