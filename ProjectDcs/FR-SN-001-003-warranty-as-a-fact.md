# FR-SN-001-003 — Warranty is a fact evaluated as at a date

@BABOK Related: BR-SN-001-003
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

"Is this warranted?" is asked on a date, by a support agent, about a unit that
may have been sold years ago and may have had cover extended. The answer cannot
be a boolean column, because the same unit is covered on one date and not on
another. Cover is therefore a **derived fact** over an interval, and today is
**injected** so the answer is testable and deterministic.

## Requirement

`WarrantyService` (today injected via the constructor):

- `isCovered(SerialNumberDto, ?string $onDate)` — cover exists only when
  `warranty_end` is recorded AND the unit is in a state that carries cover
  (`installed`). An `available` unit with a stray end date is **not** covered,
  and a `returned` unit has lost cover.
- Cover is **inclusive of the end date**: cover on the last day is still cover.
- `daysRemaining()` counts the end day.
- No `warranty_end` recorded means **no cover**, never "cover until 1970".
- `extend($serialNo, $days, $fromDate)` pushes the end date out. If the unit's
  cover has **already expired**, the extension restarts from `$fromDate`
  rather than from a date in the past. Non-positive days are rejected; an
  unknown serial throws.
- `expiringSoon($itemCode, $withinDays)` returns only cover that **ends inside
  the window**, and ignores units already expired.

## Acceptance

| Criterion | Test |
|---|---|
| Installed unit with future end is covered | `testInstalledUnitWithFutureEndIsCovered` |
| Cover inclusive of end date | `testCoverIsInclusiveOfEndDate` |
| Days remaining counts the end day | `testDaysRemainingCountsEndDay` |
| Available unit has no cover despite an end date | `testAvailableUnitHasNoCoverEvenWithAnEndDate` |
| Returned unit loses cover | `testReturnedUnitLosesCover` |
| No warranty recorded means no cover | `testNoWarrantyRecordedMeansNoCover` |
| Extend pushes the end date out | `testExtendPushesEndDateOut` |
| Extend restarts from reference once expired | `testExtendRestartsFromReferenceWhenAlreadyExpired` |
| Non-positive days rejected | `testExtendRejectsNonPositiveDays` |
| Unknown serial throws on extend | `testExtendUnknownSerialThrows` |
| Expiring-soon ignores already-expired cover | `testExpiringSoonIgnoresAlreadyExpired` |
| Default date is the frozen `today` | `testDefaultDateIsFrozenToday` |

## Traceability

Tests : `tests/Unit/WarrantyServiceTest.php`
@BABOK Need-tag: SN-DATE-INJECTED - BABOK 7.1 (time is a dependency, not ambient state)
