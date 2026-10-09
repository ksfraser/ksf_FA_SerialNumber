# FR-SN-001-002 — Every location change is logged, append-only

@BABOK Related: BR-SN-001
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

These units are the expensive stock, so "where is it" must be answerable
*historically*, not only currently. A single mutable `loc_code` column answers
only "where is it now" and cannot answer "where was it when it went missing".

## Requirement

- `0_ksf_serial_location_log` is **append-only**: one row per location change,
  recording `from_loc`, `to_loc`, `from_shelf`, `to_shelf`, `reason`,
  `moved_at`.
- `move()` records **both ends** of the move.
- `register()` logs only when a location was supplied at receipt — a serial
  created without a location logs nothing rather than logging a null-to-null row.
- `history()` returns newest first.
- The log is a **drift detector**: comparing its last entry against
  `0_ksf_serial_numbers.loc_code` reveals a location that was changed outside
  this module.

## Acceptance

| Criterion | Test |
|---|---|
| Receipt with a location logs it | `testRegisterWithLocationLogsReceipt` |
| Receipt without a location logs nothing | `testRegisterWithoutLocationLogsNothing` |
| Move records both ends | `testMoveRecordsBothEnds` |
| Sale leaves an audit entry | `testMarkSoldLeavesAuditEntry` |
| History is newest first | `testHistoryIsNewestFirst` |

## Traceability

Tests : `tests/Unit/SerialNumberServiceTest.php`
@BABOK Need-tag: SN-DRIFT-DETECT - BABOK 7.3 (integrity: detect out-of-band writes)
