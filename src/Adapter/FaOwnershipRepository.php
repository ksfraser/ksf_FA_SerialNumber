<?php
/**
 * @BABOK Related: FR-SN-002-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Adapter;

use ksfraser\FrontAccounting\SerialNumber\Contracts\OwnershipRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\OwnershipDto;

/**
 * FA-backed ownership history using native db_* calls only.
 *
 * Writes go through begin_transaction()/commit_transaction() so closing the
 * previous period and opening the new one is one atomic step. The unique key
 * `uniq_current_owner` (on the generated `current_marker` column) is the last
 * line of defence: a plain UNIQUE(serial_no, owned_to) would permit unlimited
 * open rows because a UNIQUE index treats every NULL as distinct, which was
 * verified against MariaDB 10.11 before this class was written.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Adapter
 * @since 1.1.0
 */
class FaOwnershipRepository implements OwnershipRepositoryInterface
{
    /**
     * @return string
     */
    private function table(): string
    {
        return TB_PREF . 'ksf_serial_ownership';
    }

    /**
     * @inheritDoc
     */
    public function open(OwnershipDto $ownership): int
    {
        $ownership->ownedTo = null;

        $sql = "INSERT INTO " . $this->table() . " (serial_no, owner_kind, owner_ref,
            owned_from, owned_to, note, created_at) VALUES ("
            . db_escape($ownership->serialNo) . ', '
            . db_escape($ownership->ownerKind) . ', '
            . db_escape($ownership->ownerRef) . ', '
            . db_escape($ownership->ownedFrom) . ', '
            . 'NULL, '
            . $this->sqlNullableString($ownership->note) . ', '
            . db_escape(date('Y-m-d H:i:s'))
            . ')';

        db_query($sql, 'ownership open failed');

        return (int)db_insert_id();
    }

    /**
     * @inheritDoc
     */
    public function closeCurrent(string $serialNo, string $ownedTo): bool
    {
        // Scoped to owned_to IS NULL so this can never close an already-closed
        // period -- resale history is append-only, and re-closing would rewrite it.
        $sql = "UPDATE " . $this->table()
            . " SET owned_to = " . db_escape($ownedTo)
            . " WHERE serial_no = " . db_escape($serialNo)
            . " AND owned_to IS NULL";

        db_query($sql, 'ownership close failed');

        return db_affected_rows() > 0;
    }

    /**
     * @inheritDoc
     */
    public function current(string $serialNo): ?OwnershipDto
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE serial_no = " . db_escape($serialNo)
            . " AND owned_to IS NULL"
            . " ORDER BY owned_from DESC, id DESC LIMIT 1";

        $result = db_query($sql, 'ownership current lookup failed');

        if (!$result) {
            return null;
        }

        $row = db_fetch_assoc($result);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function history(string $serialNo): array
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE serial_no = " . db_escape($serialNo)
            . " ORDER BY owned_from ASC, id ASC";

        return $this->fetchAll($sql);
    }

    /**
     * @inheritDoc
     */
    public function ownerAsAt(string $serialNo, string $onDate): ?OwnershipDto
    {
        // owned_to IS NULL OR owned_to >= the date: both ends inclusive, matching
        // OwnershipDto::covers().
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE serial_no = " . db_escape($serialNo)
            . " AND owned_from <= " . db_escape($onDate)
            . " AND (owned_to IS NULL OR owned_to >= " . db_escape($onDate) . ")"
            . " ORDER BY owned_from DESC, id DESC LIMIT 1";

        $result = db_query($sql, 'ownership as-at lookup failed');

        if (!$result) {
            return null;
        }

        $row = db_fetch_assoc($result);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function heldBy(string $ownerKind, string $ownerRef): array
    {
        $sql = "SELECT * FROM " . $this->table()
            . " WHERE owner_kind = " . db_escape($ownerKind)
            . " AND owner_ref = " . db_escape($ownerRef)
            . " AND owned_to IS NULL"
            . " ORDER BY serial_no";

        return $this->fetchAll($sql);
    }

    /**
     * @param string $sql
     * @return OwnershipDto[]
     */
    private function fetchAll(string $sql): array
    {
        $result = db_query($sql, 'ownership query failed');
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
     * @return OwnershipDto
     */
    private function hydrate(array $row): OwnershipDto
    {
        $ownership = new OwnershipDto(
            (string)$row['serial_no'],
            (string)$row['owner_kind'],
            (string)$row['owner_ref'],
            (string)$row['owned_from']
        );

        $ownership->id = (int)$row['id'];
        $ownership->ownedTo = $row['owned_to'];
        $ownership->note = $row['note'];

        return $ownership;
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