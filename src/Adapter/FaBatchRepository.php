<?php
/**
 * @BABOK Related: FR-SN-001-004
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Adapter;

use ksfraser\FrontAccounting\SerialNumber\Contracts\BatchRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\BatchNumberDto;

/**
 * FA-backed batch persistence using native db_* calls only.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Adapter
 * @since 1.0.0
 */
class FaBatchRepository implements BatchRepositoryInterface
{
    /**
     * @return string
     */
    private function table(): string
    {
        return TB_PREF . 'ksf_batch_numbers';
    }

    /**
     * @inheritDoc
     */
    public function insert(BatchNumberDto $batch): int
    {
        $sql = "INSERT INTO " . $this->table() . " (batch_no, item_code, qty, batch_date,
            expiry_date, supplier_ref, location_code, status, notes, created_at, updated_at)
            VALUES ("
            . db_escape($batch->batchNo) . ', '
            . db_escape($batch->itemCode) . ', '
            . (float)$batch->qty . ', '
            . $this->sqlNullableString($batch->batchDate) . ', '
            . $this->sqlNullableString($batch->expiryDate) . ', '
            . $this->sqlNullableString($batch->supplierRef) . ', '
            . $this->sqlNullableString($batch->locationCode) . ', '
            . db_escape($batch->status) . ', '
            . db_escape($batch->notes) . ', '
            . db_escape(date('Y-m-d H:i:s')) . ', '
            . db_escape(date('Y-m-d H:i:s'))
            . ')';

        db_query($sql, 'batch insert failed');

        return (int)db_insert_id();
    }

    /**
     * @inheritDoc
     */
    public function update(BatchNumberDto $batch): void
    {
        $sql = "UPDATE " . $this->table() . " SET
            qty = " . (float)$batch->qty . ',
            batch_date = ' . $this->sqlNullableString($batch->batchDate) . ',
            expiry_date = ' . $this->sqlNullableString($batch->expiryDate) . ',
            supplier_ref = ' . $this->sqlNullableString($batch->supplierRef) . ',
            location_code = ' . $this->sqlNullableString($batch->locationCode) . ',
            status = ' . db_escape($batch->status) . ',
            notes = ' . db_escape($batch->notes) . ',
            updated_at = ' . db_escape(date('Y-m-d H:i:s')) . '
            WHERE id = ' . (int)$batch->id;

        db_query($sql, 'batch update failed');
    }

    /**
     * @inheritDoc
     */
    public function find(string $batchNo, string $itemCode): ?BatchNumberDto
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE batch_no = " . db_escape($batchNo)
            . " AND item_code = " . db_escape($itemCode)
            . " LIMIT 1";

        $result = db_query($sql, 'batch lookup failed');

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

        return $this->fetchAll($sql . ' ORDER BY batch_no');
    }

    /**
     * @inheritDoc
     */
    public function decrementQty(int $id, float $qty): void
    {
        db_query(
            "UPDATE " . $this->table() . " SET qty = qty - " . (float)$qty
            . ", updated_at = " . db_escape(date('Y-m-d H:i:s'))
            . " WHERE id = " . (int)$id,
            'batch decrement failed'
        );
    }

    /**
     * @inheritDoc
     */
    public function setQty(int $id, float $qty): void
    {
        db_query(
            "UPDATE " . $this->table() . " SET qty = " . (float)$qty
            . ", updated_at = " . db_escape(date('Y-m-d H:i:s'))
            . " WHERE id = " . (int)$id,
            'batch qty set failed'
        );
    }

    /**
     * @inheritDoc
     *
     * MySQL sorts NULL first ascending, so the explicit CASE puts undated
     * batches LAST: they never expire and must be the fallback, not the first
     * thing issued.
     */
    public function findFefoCandidates(string $itemCode, string $onDate): array
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE item_code = " . db_escape($itemCode)
            . " AND status = 'active'"
            . " AND qty > 0"
            . " AND (expiry_date IS NULL OR expiry_date >= " . db_escape($onDate) . ")"
            . " ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,"
            . " expiry_date ASC, batch_no ASC";

        return $this->fetchAll($sql);
    }

    /**
     * @param string $sql
     * @return BatchNumberDto[]
     */
    private function fetchAll(string $sql): array
    {
        $result = db_query($sql, 'batch query failed');
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
     * @return BatchNumberDto
     */
    private function hydrate(array $row): BatchNumberDto
    {
        $batch = new BatchNumberDto((string)$row['batch_no'], (string)$row['item_code']);
        $batch->id = (int)$row['id'];
        $batch->qty = (float)$row['qty'];
        $batch->batchDate = $row['batch_date'];
        $batch->expiryDate = $row['expiry_date'];
        $batch->supplierRef = $row['supplier_ref'];
        $batch->locationCode = $row['location_code'];
        $batch->status = (string)$row['status'];
        $batch->notes = (string)$row['notes'];

        return $batch;
    }

    /**
     * @param string|null $value
     * @return string
     */
    private function sqlNullableString(?string $value): string
    {
        return ($value === null || $value === '') ? 'NULL' : db_escape($value);
    }
}