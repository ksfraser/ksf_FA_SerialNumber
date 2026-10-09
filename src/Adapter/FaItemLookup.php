<?php
/**
 * @BABOK Related: FR-SN-004-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Adapter;

use ksfraser\FrontAccounting\SerialNumber\Contracts\ItemLookupInterface;

/**
 * Stock master lookup via FA's own functions.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Adapter
 * @since 1.1.0
 */
class FaItemLookup implements ItemLookupInterface
{
    /**
     * @inheritDoc
     */
    public function exists(string $itemCode): bool
    {
        if ($itemCode === '') {
            return false;
        }

        return get_stock_id($itemCode) !== 0;
    }

    /**
     * @inheritDoc
     */
    public function description(string $itemCode): ?string
    {
        $description = get_stock_description($itemCode);

        return ($description === false || $description === null) ? null : (string)$description;
    }
}
