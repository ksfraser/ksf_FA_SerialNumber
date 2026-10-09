<?php
/**
 * @BABOK Related: FR-SN-004-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Fakes;

use ksfraser\FrontAccounting\SerialNumber\Contracts\ItemLookupInterface;

/**
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Fakes
 * @since 1.1.0
 */
class InMemoryItemLookup implements ItemLookupInterface
{
    /** @var array<string,string> item code => description */
    private $items = array();

    /**
     * @param string $itemCode
     * @param string $description
     * @return void
     */
    public function add(string $itemCode, string $description = ''): void
    {
        $this->items[$itemCode] = $description;
    }

    /**
     * @param string $itemCode
     * @return bool
     */
    public function exists(string $itemCode): bool
    {
        return isset($this->items[$itemCode]);
    }

    /**
     * @param string $itemCode
     * @return string|null
     */
    public function description(string $itemCode): ?string
    {
        return $this->items[$itemCode] ?? null;
    }
}
