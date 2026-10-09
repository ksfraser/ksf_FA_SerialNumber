<?php
/**
 * @BABOK Related: FR-SN-005-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

/**
 * What one sales-order line needs from the picker, and what it already has.
 *
 * Quantity is compared against serials: a serial-controlled line needs one serial
 * per unit. That is the rule that FA cannot express -- 0_sales_order_details has
 * a quantity and a stock code, and nothing anywhere ties "one serial" to "one
 * unit of quantity".
 *
 * PHP 7.3 compatible: untyped properties, every one carrying a default.
 * See AGENTS_ARCH.md §1.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Dto
 * @since 1.1.0
 */
class CartSerialRequirement
{
    /** @var string FA stock id for the line. */
    public $itemCode = '';

    /** @var string FA stock description, when known. */
    public $itemDescription = '';

    /** @var float Quantity on the line. */
    public $qty = 0.0;

    /** @var bool False when the item is not serial-controlled. */
    public $requiresSerial = false;

    /**
     * @var int Default warranty days for the item, from 0_ksf_serial_control.
     *
     * This is the item-level default, applied to each unit at commit. An
     * individual unit's actual cover then lives in its own warranty_end.
     */
    public $warrantyDays = 0;

    /** @var string[] Serials already assigned to this line. */
    public $assignedSerials = array();

    /**
     * @param string $itemCode
     * @param float  $qty
     */
    public function __construct(string $itemCode = '', float $qty = 0.0)
    {
        $this->itemCode = $itemCode;
        $this->qty = $qty;
    }

    /**
     * How many serials this line still needs.
     *
     * Always 0 for an item that is not serial-controlled: a caller asking this is
     * asking about serials, and answering "5" for a line of ordinary widgets
     * would push the picker to scan serials that do not exist.
     *
     * @return int Never negative, even if over-assigned.
     */
    public function shortfall(): int
    {
        if (!$this->requiresSerial) {
            return 0;
        }

        $needed = (int)round($this->qty) - count($this->assignedSerials);

        return $needed > 0 ? $needed : 0;
    }

    /**
     * @return bool
     */
    public function isSatisfied(): bool
    {
        if (!$this->requiresSerial) {
            return true;
        }

        return $this->shortfall() === 0;
    }

    /**
     * @return bool
     */
    public function isOverAssigned(): bool
    {
        return count($this->assignedSerials) > (int)round($this->qty);
    }

    /**
     * @return string
     */
    public function describe(): string
    {
        if (!$this->requiresSerial) {
            return (string)($this->itemDescription !== '' ? $this->itemDescription : $this->itemCode);
        }

        $short = $this->shortfall();

        if ($short === 0) {
            return $this->itemCode . ' -- ' . count($this->assignedSerials) . ' serial(s) assigned';
        }

        return $this->itemCode . ' -- ' . $short . ' more serial(s) needed';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'item_code'        => $this->itemCode,
            'item_description' => $this->itemDescription,
            'qty'              => $this->qty,
            'requires_serial'  => $this->requiresSerial,
            'assigned_serials' => $this->assignedSerials,
            'warranty_days'    => $this->warrantyDays,
            'shortfall'        => $this->shortfall(),
            'satisfied'        => $this->isSatisfied(),
        );
    }
}