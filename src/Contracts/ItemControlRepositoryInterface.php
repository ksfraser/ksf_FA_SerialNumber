<?php
/**
 * @BABOK Related: FR-SN-004-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Contracts;

/**
 * Item-level serial control.
 *
 * FA's 0_stock_master carries no "needs a serial" flag, so this is how the
 * module knows an item is serial-controlled at all. Without it the scan resolver
 * could not distinguish an ordinary item from one where a serial is mandatory.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Contracts
 * @since 1.1.0
 */
interface ItemControlRepositoryInterface
{
    /**
     * Is this item serial-controlled?
     *
     * @param string $itemCode
     * @return bool False when the item is not controlled, or is unknown.
     */
    public function requiresSerial(string $itemCode): bool;

    /**
     * Default warranty days for an item.
     *
     * 0 means no default cover -- which is NOT the same as an existing unit
     * having no warranty; that lives on the unit itself.
     *
     * @param string $itemCode
     * @return int
     */
    public function warrantyDays(string $itemCode): int;

    /**
     * Mark an item as serial-controlled.
     *
     * @param string $itemCode
     * @param int    $warrantyDays Default cover; 0 for none.
     * @return void
     */
    public function control(string $itemCode, int $warrantyDays = 0): void;

    /**
     * Stop controlling an item.
     *
     * Existing units keep their serials and their history; this only stops NEW
     * movements from demanding one.
     *
     * @param string $itemCode
     * @return void
     */
    public function release(string $itemCode): void;
}