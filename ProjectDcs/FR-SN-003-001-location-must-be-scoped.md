# FR-SN-003-001 — A serial's location must be a full scoped pick face

@BABOK Related: BR-SN-001; FR-WH-001-004
Status : Approved — implemented
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

## The rule that makes it safe

The face is either **wholly specified or wholly absent — never partial**:

- No face at all is legitimate: the unit has not been put away yet, or has left
  the building.
- A partial face is refused with `InvalidSerialStateException`, because
  `loc_code + shelf_id` with no aisle cannot be resolved to a real position.

`SerialNumberDto::pickFace()` returns the full scoped key or null;
`isShelved()` distinguishes "at a location" from "on a specific bin".

## Acceptance

| Criterion | Test |
|---|---|
| A unit with no location is legitimate | `testAUnitWithNoLocationIsLegitimate` |
| The bin is part of the pick face | `testTheBinIsPartOfThePickFace` |
| A shelf without an aisle is refused | `testAShelfWithoutAnAisleIsRefused` |
| An aisle without a bin is refused | `testAnAisleWithoutABinIsRefused` |
| A bin with no location is refused | `testABinWithNoLocationIsRefused` |
| `assignLocation` refuses a partial face | `testAssignLocationRefusesAPartialFace` |
| `assignLocation` accepts the full face | `testAssignLocationAcceptsTheFullFace` |
| Goods-in lands on the reserved UNASSIGNED face | `testGoodsInLandsOnTheReservedUnassignedFace` |
| Put-away moves reserved -> real bin | `testPutAwayMovesFromTheReservedFaceToARealBin` |
| Same bin number on different shelves is a different face | `testTheSameBinNumberOnDifferentShelvesIsADifferentFace` |
| Same face in two warehouses is two faces | `testTheSameFaceInTwoWarehousesIsTwoFaces` |
| Retiring clears the whole face but keeps the trail | `testRetiringClearsTheWholeFace` |

Tests : `tests/Unit/PickFaceTest.php`

## Traceability

BRs : BR-SN-001
UTs : UT-SN-003-001-001, UT-SN-003-001-002
Related : FR-WH-001-001 (location-scoped indices), FR-WH-001-004 (bin is the
pick face).

## Known gap

The **scan resolver** — resolving a scanned code to a serial, an item, or a
serial's current face — is not built yet. `findByFace()` is the query it needs,
but the discriminating DTO that separates "ordinary item" / "serialized item,
serial required" / "serial -> SKU and face" is still to be written.
