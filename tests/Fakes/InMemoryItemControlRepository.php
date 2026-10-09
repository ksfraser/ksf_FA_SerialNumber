<?php
/**
 * @BABOK Related: FR-SN-004-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Fakes;

use ksfraser\FrontAccounting\SerialNumber\Contracts\ItemControlRepositoryInterface;

/**
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Fakes
 * @since 1.1.0
 */
class InMemoryItemControlRepository implements ItemControlRepositoryInterface
{
    /** @var array<string,array{requires:int,warranty:int}> */
    private $items = array();

    /**
     * @param string $itemCode
     * @param int    $warrantyDays
     * @return void
     */
    public function control(string $itemCode, int $warrantyDays = 0): void
    {
        $this->items[$itemCode] = array('requires' => 1, 'warranty' => $warrantyDays);
    }

    /**
     * @param string $itemCode
     * @return void
     */
    public function release(string $itemCode): void
    {
        unset($this->items[$itemCode]);
    }

    /**
     * @param string $itemCode
     * @return bool
     */
    public function requiresSerial(string $itemCode): bool
    {
        // No row means not controlled -- otherwise every unlisted item would
        // demand a serial and nothing could be picked.
        return isset($this->items[$itemCode]) && $this->items[$itemCode]['requires'] === 1;
    }

    /**
     * @param string $itemCode
     * @return int
     */
    public function warrantyDays(string $itemCode): int
    {
        return isset($this->items[$itemCode]) ? $this->items[$itemCode]['warranty'] : 0;
    }
}
