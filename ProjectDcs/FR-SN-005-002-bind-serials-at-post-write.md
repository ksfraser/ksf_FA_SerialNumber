# FR-SN-005-002 — Serials are bound to the delivery at post-write

@BABOK Related: BR-SN-001-005, FR-SN-005-001
Status : Approved — service implemented; hook wiring pending
Module : ksf_FA_SerialNumber

## Need

Captured serials are only in memory. They must become the unit's real state at
the moment the delivery becomes real, and they must not become so before then.

## Requirement

`DeliverySerialCommit::commit(array $requirements, int $transNo, string $onDate,
string $ownerKind, string $ownerRef)`

For each assigned serial: `reserve()` then `markSold(...)`, applying the item's
default `warrantyDays` and opening an ownership period on the delivery date.

### Why post-write, and what must not happen there

Verified mechanics:

- `sales/includes/db/sales_delivery_db.inc:200` calls
  `hook_db_postwrite($delivery, ST_CUSTDELIVERY)` and line 201 is
  `commit_transaction()`. Post-write is therefore the **first moment the
  delivery has a transaction number**, and it is **inside** the transaction.
- Because it is inside, raising here **rolls the delivery back** — which is the
  behaviour we want. A delivery whose serials could not be bound must not
  survive; a machine delivered with no serial recorded is exactly the loss this
  design exists to prevent.
- Because it is one line before the commit, the hook **must not `exit`**.
- A **redirect** is not safe here at all: output has effectively been decided.
  `hook_invoke_all('pre_header')` at `includes/page/header.inc:132` is the only
  safe redirect point.

### What this class deliberately does not do

It does not commit the transaction and does not redirect. Both belong
elsewhere — commit is FA's, redirect belongs to `pre_header`.

## Acceptance

Covered by `tests/Unit/DeliverySerialCommitTest.php` (8 tests):

| Criterion | Test |
|---|---|
| Every captured serial is sold | `testCommitSellsEveryCapturedSerial` |
| Uncontrolled items are left alone | `testCommitLeavesUncontrolledItemsAlone` |
| Warranty starts from the item default | `testCommitStartsTheWarrantyFromTheItemDefault` |
| An ownership period is opened | `testCommitOpensAnOwnershipPeriod` |
| A committed serial is no longer pickable | `testACommittedSerialIsNoLongerPickable` |
| Nothing is committed when nothing was captured | `testNothingCommittedWhenNoSerialsWereCaptured` |

`testCommitStartsTheWarrantyFromTheItemDefault` asserts `2028-02-29` for 730 days
from `2026-03-01`, which happens to be a leap year — the assertion would fail if
the day arithmetic were naive.

## Traceability

BRs : BR-SN-001-005
Implements : `DeliverySerialCommit`.

## Outstanding

The `db_postwrite` hook wiring is **not** yet written, and neither is the
`pre_header` redirect that refuses to proceed while a line is short. Until those
land, capture is enforced only by the picker using the capture service — nothing
yet blocks the delivery itself.
