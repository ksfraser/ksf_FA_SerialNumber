# FR-SN-003-001 — A serial's location must be a full scoped pick face

@BABOK Related: BR-SN-001; FR-WH-001-004
Status : **REQUIRED, NOT IMPLEMENTED — cross-module mismatch**
Module : ksf_FA_SerialNumber (depends on ksf_FA_Warehouse)

## The mismatch

`0_ksf_serial_numbers` stores its position as:

```sql
`loc_code`  VARCHAR(5)  DEFAULT NULL,
`shelf_id`  INT(11)     DEFAULT NULL,   -- indexed
```

`0_ksf_wh_stock_home` — the authoritative pick face — stores:

```sql
`stk_code`, `loc_code`, `aisle_id`, `shelf_id`, `bin_id`, `qty`
```

Three problems:

1. **No bin.** The bin is the pick face (FR-WH-001-004). A serial can be
   recorded against a shelf but not against the specific bin, so "which box is it
   in" is unanswerable.
2. **No aisle.** `shelf_id` alone is ambiguous: shelf 2 of aisle 4 and shelf 2 of
   aisle 9 are different shelves. The warehouse keys are **location-scoped and
   parent-scoped**, so a bare `shelf_id` is not a valid reference on its own.
3. **`VARCHAR(5)` is too narrow** for anything but a very short location code,
   while `0_ksf_wh_bin.loc_code` and friends are wider. A serial location that
   does not fit the same definition as the warehouse's cannot be joined safely.

## Requirement

Replace the pair with the full scoped key:

```sql
ALTER TABLE `0_ksf_serial_numbers`
  CHANGE `shelf_id` `bin_id`   INT(11) DEFAULT NULL,
  ADD COLUMN `aisle_id` INT(11) DEFAULT NULL,
  ADD COLUMN `shelf_id` INT(11) DEFAULT NULL,
  ADD KEY `idx_face` (`loc_code`, `aisle_id`, `shelf_id`, `bin_id`);
```

`SerialMoveDto` and `SerialNumberService::assignLocation()` / `move()` must take
the full `(locCode, aisleId, shelfId, binId)` rather than `?int $shelfId`.
`0_ksf_serial_location_log` needs the same widening.

## Sequencing

This is a **breaking API change** to `SerialNumberService::assignLocation()` and
`move()`. Both modules are new and not yet activated, so change it before either
is switched on rather than migrating live data.

## Acceptance

| Criterion | Status |
|---|---|
| Serial position covers aisle, shelf and bin | not implemented |
| A serial can be joined to `0_ksf_wh_stock_home` on the full key | not implemented |
| `loc_code` width matches the warehouse definition | not implemented |
| Scan resolves a serial to its exact pick face | not implemented |

## Traceability

Related : FR-WH-001-001 (location-scoped indices), FR-WH-001-004 (bin is the
pick face), and the scan-resolver DTO still to be built.
