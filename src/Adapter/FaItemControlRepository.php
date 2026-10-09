<?php
/**
 * @BABOK Related: FR-SN-004-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Adapter;

use ksfraser\FrontAccounting\SerialNumber\Contracts\ItemControlRepositoryInterface;

/**
 * FA-backed item serial control using native db_* calls only.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Adapter
 * @since 1.1.0
 */
class FaItemControlRepository implements ItemControlRepositoryInterface
{
    /**
     * @return string
     */
    private function table(): string
    {
        return TB_PREF . 'ksf_serial_control';
    }

    /**
     * @inheritDoc
     */
    public function requiresSerial(string $itemCode): bool
    {
        $row = $this->fetch($itemCode);

        // An unknown item is not controlled. Treating "no row" as "controlled"
        // would make every unlisted item demand a serial, which would stop the
        // whole business picking anything.
        return $row !== null && (int)$row['requires_serial'] === 1;
    }

    /**
     * @inheritDoc
     */
    public function warrantyDays(string $itemCode): int
    {
        $row = $this->fetch($itemCode);

        return $row === null ? 0 : (int)$row['warranty_days'];
    }

    /**
     * @inheritDoc
     */
    public function control(string $itemCode, int $warrantyDays = 0): void
    {
        $sql = "INSERT INTO " . $this->table()
            . " (item_code, requires_serial, warranty_days) VALUES ("
            . db_escape($itemCode) . ', 1, ' . (int)$warrantyDays . ')'
            . " ON DUPLICATE KEY UPDATE requires_serial = 1, warranty_days = "
            . (int)$warrantyDays;

        db_query($sql, 'serial control upsert failed');
    }

    /**
     * @inheritDoc
     */
    public function release(string $itemCode): void
    {
        $sql = "DELETE FROM " . $this->table()
            . " WHERE item_code = " . db_escape($itemCode);

        db_query($sql, 'serial control release failed');
    }

    /**
     * @param string $itemCode
     * @return array<string,mixed>|null
     */
    private function fetch(string $itemCode): ?array
    {
        $sql = "SELECT requires_serial, warranty_days FROM " . $this->table()
            . " WHERE item_code = " . db_escape($itemCode) . " LIMIT 1";

        $result = db_query($sql, 'serial control lookup failed');

        if (!$result) {
            return null;
        }

        $row = db_fetch_assoc($result);

        return $row ? $row : null;
    }
}