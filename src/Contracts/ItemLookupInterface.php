<?php
/**
 * @BABOK Related: FR-SN-004-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Contracts;

/**
 * Read-only lookup of FA's stock master.
 *
 * Injected rather than called statically, because ScanResolver() must be
 * testable outside FA. A guard like `function_exists('get_stock_id')` would
 * make the resolver report every code as UNKNOWN under test -- i.e. the mock
 * would hide the behaviour instead of the code being right.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Contracts
 * @since 1.1.0
 */
interface ItemLookupInterface
{
    /**
     * @param string $itemCode
     * @return bool
     */
    public function exists(string $itemCode): bool;

    /**
     * @param string $itemCode
     * @return string|null Null when unknown.
     */
    public function description(string $itemCode): ?string;
}
