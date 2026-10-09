# FR-SN-001-001 — Serial lifecycle is a controlled state machine

@BABOK Related: BR-SN-001-001
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

If status were a free-text column, an operator could sell a retired unit or put
an installed unit straight back on the shelf, and the warehouse aggregate would
silently disagree with the movement ledger. The lifecycle is therefore
**enforced**, not documented.

## States

`available`, `reserved`, `installed`, `returned`, `retired` — constants on
`SerialNumberDto`.

## Requirement

`SerialNumberService` enforces every transition through the private
`transition()`. Rules:

- A **retired** unit is terminal and `retire()` is **idempotent** (retiring
  twice is not an error — the row is already in the target state).
- A **retired** unit cannot be sold.
- An **installed** unit cannot be put straight back in stock; it must be
  `returnSerial()`-ed first, which is what clears customer and warranty.
- A **sold** unit cannot be returned — returns are goods-in of a different kind.
- `markSold()` twice is rejected.
- `reserve()` / `unreserve()` round-trip and do not imply a location change.
- `markSold()` starts the warranty from the **install date**, not the sale date,
  when an install date is recorded.

## Acceptance

| Criterion | Test |
|---|---|
| Register creates an `available` unit | `testRegisterCreatesAvailableSerial` |
| Empty serial rejected | `testRegisterTrimsSerialAndRejectsEmpty` |
| Item code required | `testRegisterRequiresItemCode` |
| Duplicate serial rejected | `testRegisterRejectsDuplicateSerial` |
| Retire is terminal and idempotent | `testRetireIsTerminalAndIdempotent` |
| Retired unit cannot be sold | `testRetiredUnitCannotBeSold` |
| Installed unit cannot go straight back to stock | `testInstalledUnitCannotBePutBackDirectlyInStock` |
| Sold unit cannot be returned | `testReturnOfUnsoldUnitIsRejected` |
| Sold twice rejected | `testMarkSoldTwiceIsRejected` |
| Reserve/unreserve round-trip | `testReserveAndUnreserveRoundTrip` |

## Traceability

Tests : `tests/Unit/SerialNumberServiceTest.php`
@BABOK Need-tag: SN-LOUD-REFUSE - BABOK 7.2 (illegal transition must fail loudly)
