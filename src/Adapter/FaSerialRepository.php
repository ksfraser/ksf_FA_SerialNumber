<?php
/**
 * @BABOK Related: FR-SN-001-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Adapter;

use ksfraser\FrontAccounting\SerialNumber\Contracts\SerialRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialMoveDto;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;

/**
 * FA-backed serial persistence using native db_* calls only.
 *
 * There is deliberately no PDO or mysqli handle here: inside FA the db_* layer
 * is the transport (see AGENTS_ARCH.md §"PDO question"). The DTOs exist so the
 * services can be unit tested without a database; this class is the only place
 * that touches SQL.
 *
 * Table names are written with the literal TB_PREF concatenation FA uses
 * everywhere, because at runtime the prefix is already substituted -- the literal
 * "0_" form is only required in sql/install.sql, which FA parses itself.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Adapter
 * @since 1.0.0
 */
class FaSerialRepository implements SerialRepositoryInterface
{
    /**
     * @return string
     */
    private function table(): string
    {
        return TB_PREF . 'ksf_serial_numbers';
    }

    /**
     * @return string
     */
    private function logTable(): string
    {
        return TB_PREF . 'ksf_serial_location_log';
    }

    /**
     * @inheritDoc
     */
    public function insert(SerialNumberDto $serial): int
    {
        $sql = "INSERT INTO " . $this->table() . " (serial_no, item_code, status, loc_code,
            shelf_id, batch_no, supplier_ref, purchase_date, purchase_cost, currency,
            sold_to, sold_date, installed_date, warranty_end, notes, created_at, updated_at)
            VALUES ("
            . db_escape($serial->serialNo) . ', '
            . db_escape($serial->itemCode) . ', '
            . db_escape($serial->status) . ', '
            . $this->sqlNullableString($serial->locCode) . ', '
            . $this->sqlNullableInt($serial->shelfId) . ', '
            . $this->sqlNullableString($serial->batchNo) . ', '
            . $this->sqlNullableString($serial->supplierRef) . ', '
            . $this->sqlNullableString($serial->purchaseDate) . ', '
            . $this->sqlNullableFloat($serial->purchaseCost) . ', '
            . $this->sqlNullableString($serial->currency) . ', '
            . $this->sqlNullableString($serial->soldTo) . ', '
            . $this->sqlNullableString($serial->soldDate) . ', '
            . $this->sqlNullableString($serial->installedDate) . ', '
            . $this->sqlNullableString($serial->warrantyEnd) . ', '
            . db_escape($serial->notes) . ', '
            . db_escape(date('Y-m-d H:i:s')) . ', '
            . db_escape(date('Y-m-d H:i:s'))
            . ')';

        db_query($sql, 'serial insert failed');

        return (int)db_insert_id();
    }

    /**
     * @inheritDoc
     */
    public function update(SerialNumberDto $serial): void
    {
        $sql = "UPDATE " . $this->table() . " SET
            item_code = " . db_escape($serial->itemCode) . ',
            status = ' . db_escape($serial->status) . ',
            loc_code = ' . $this->sqlNullableString($serial->locCode) . ',
            shelf_id = ' . $this->sqlNullableInt($serial->shelfId) . ',
            batch_no = ' . $this->sqlNullableString($serial->batchNo) . ',
            supplier_ref = ' . $this->sqlNullableString($serial->supplierRef) . ',
            purchase_date = ' . $this->sqlNullableString($serial->purchaseDate) . ',
            purchase_cost = ' . $this->sqlNullableFloat($serial->purchaseCost) . ',
            currency = ' . $this->sqlNullableString($serial->currency) . ',
            sold_to = ' . $this->sqlNullableString($serial->soldTo) . ',
            sold_date = ' . $this->sqlNullableString($serial->soldDate) . ',
            installed_date = ' . $this->sqlNullableString($serial->installedDate) . ',
            warranty_end = ' . $this->sqlNullableString($serial->warrantyEnd) . ',
            notes = ' . db_escape($serial->notes) . ',
            updated_at = ' . db_escape(date('Y-m-d H:i:s')) . '
            WHERE id = ' . (int)$serial->id;

        db_query($sql, 'serial update failed');
    }

    /**
     * @inheritDoc
     */
    public function findBySerialNo(string $serialNo): ?SerialNumberDto
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE serial_no = " . db_escape($serialNo) . " LIMIT 1";

        $result = db_query($sql, 'serial lookup failed');

        if (!$result) {
            return null;
        }

        $row = db_fetch_assoc($result);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function findByItem(string $itemCode, ?string $status = null): array
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE item_code = " . db_escape($itemCode);

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ' . db_escape($status);
        }

        return $this->fetchAll($sql . ' ORDER BY serial_no');
    }

    /**
     * @inheritDoc
     */
    public function findByLocation(string $locCode, ?string $status = null): array
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE loc_code = " . db_escape($locCode);

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ' . db_escape($status);
        }

        return $this->fetchAll($sql . ' ORDER BY item_code, serial_no');
    }

    /**
     * @inheritDoc
     */
    public function findByShelf(int $shelfId): array
    {
        return $this->fetchAll(
            "SELECT * FROM " . $this->table()
            . " WHERE shelf_id = " . (int)$shelfId
            . " ORDER BY item_code, serial_no"
        );
    }

    /**
     * @inheritDoc
     */
    public function appendMove(SerialMoveDto $move): void
    {
        $sql = "INSERT INTO " . $this->logTable() . " (serial_no, from_loc_code, to_loc_code,
            from_shelf_id, to_shelf_id, reason, moved_by, moved_at) VALUES ("
            . db_escape($move->serialNo) . ', '
            . $this->sqlNullableString($move->fromLocCode) . ', '
            . $this->sqlNullableString($move->toLocCode) . ', '
            . $this->sqlNullableInt($move->fromShelfId) . ', '
            . $this->sqlNullableInt($move->toShelfId) . ', '
            . db_escape($move->reason) . ', '
            . $this->sqlNullableString($move->movedBy) . ', '
            . db_escape($move->movedAt)
            . ')';

        db_query($sql, 'serial move log insert failed');
    }

    /**
     * @inheritDoc
     */
    public function movesFor(string $serialNo): array
    {
        $sql = "SELECT * FROM " . $this->logTable()
            . " WHERE serial_no = " . db_escape($serialNo)
            . " ORDER BY moved_at DESC, id DESC";

        $result = db_query($sql, 'serial move log lookup failed');
        $out = array();

        if (!$result) {
            return $out;
        }

        while ($row = db_fetch_assoc($result)) {
            $move = new SerialMoveDto();
            $move->id = (int)$row['id'];
            $move->serialNo = (string)$row['serial_no'];
            $move->fromLocCode = $row['from_loc_code'];
            $move->toLocCode = $row['to_loc_code'];
            $move->fromShelfId = $row['from_shelf_id'] === null ? null : (int)$row['from_shelf_id'];
            $move->toShelfId = $row['to_shelf_id'] === null ? null : (int)$row['to_shelf_id'];
            $move->reason = (string)$row['reason'];
            $move->movedBy = $row['moved_by'];
            $move->movedAt = (string)$row['moved_at'];
            $out[] = $move;
        }

        return $out;
    }

    /**
     * @inheritDoc
     */
    public function exists(string $serialNo): bool
    {
        $sql = "SELECT id FROM " . $this->table()
            . " WHERE serial_no = " . db_escape($serialNo) . " LIMIT 1";

        $result = db_query($sql, 'serial existence check failed');

        return $result ? db_num_rows($result) > 0 : false;
    }

    /**
     * @param string $sql
     * @return SerialNumberDto[]
     */
    private function fetchAll(string $sql): array
    {
        $result = db_query($sql, 'serial query failed');
        $out = array();

        if (!$result) {
            return $out;
        }

        while ($row = db_fetch_assoc($result)) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return SerialNumberDto
     */
    private function hydrate(array $row): SerialNumberDto
    {
        $serial = new SerialNumberDto((string)$row['serial_no'], (string)$row['item_code']);
        $serial->id = (int)$row['id'];
        $serial->status = (string)$row['status'];
        $serial->locCode = $row['loc_code'];
        $serial->shelfId = $row['shelf_id'] === null ? null : (int)$row['shelf_id'];
        $serial->batchNo = $row['batch_no'];
        $serial->supplierRef = $row['supplier_ref'];
        $serial->purchaseDate = $row['purchase_date'];
        $serial->purchaseCost = $row['purchase_cost'] === null ? null : (float)$row['purchase_cost'];
        $serial->currency = $row['currency'];
        $serial->soldTo = $row['sold_to'];
        $serial->soldDate = $row['sold_date'];
        $serial->installedDate = $row['installed_date'];
        $serial->warrantyEnd = $row['warranty_end'];
        $serial->notes = (string)$row['notes'];

        return $serial;
    }

    /**
     * @param string|null $value
     * @return string
     */
    private function sqlNullableString(?string $value): string
    {
        return ($value === null || $value === '') ? 'NULL' : db_escape($value);
    }

    /**
     * @param int|null $value
     * @return string
     */
    private function sqlNullableInt(?int $value): string
    {
        return $value === null ? 'NULL' : (string)(int)$value;
    }

    /**
     * @param float|null $value
     * @return string
     */
    private function sqlNullableFloat(?float $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return (string)(float)$value;
    }
}