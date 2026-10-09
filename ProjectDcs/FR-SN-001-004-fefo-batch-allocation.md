# FR-SN-001-004 — Batch stock is allocated FEFO with a reported shortfall

@BABOK Related: BR-SN-001-004
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

Perishable and dated stock (batteries with a shelf life, consumables with lot
numbers, anything with an expiry) must be issued **oldest first**, or the
business writes off value it already paid for. When there is not enough dated
stock to fill an order, the shortfall must be **reported**, not silently
truncated — a silent truncation means the picker discovers it on the floor.

## Requirement

`BatchNumberService::allocateFefo($itemCode, $qty, $onDate): array`

- Orders candidate batches by **expiry ascending**, so the earliest expiry is
  consumed first.
- Expiry is **inclusive of the expiry date itself**: stock is usable ON its
  expiry date, so the filter is `expiry >= onDate`.
- An **undated** batch is allocated **last** — it is the least perishable, so it
  is held back as the fallback, not spent first.
- **Expired** batches are skipped.
- Allocation is **scoped to one item** — never crosses item codes.
- Zero or negative quantity returns **nothing** (declared no-op).
- When the batches cannot cover the request, the partial allocations are
  returned **and** `shortfall()` reports the uncovered remainder. A shortfall
  of 0 when fully allocated.
- `writeOffExpired($itemCode, $onDate)` marks expired batches and returns the
  total written off; a batch written off is not allocated afterwards.
- A batch with **no expiry recorded never expires**.
- Fractional quantities are allowed.

## Acceptance

| Criterion | Test |
|---|---|
| Earliest expiry consumed first | `testFefoConsumesEarliestExpiryFirst` |
| Allocation spans multiple batches | `testFefoSpansMultipleBatches` |
| Undated batch allocated last | `testFefoUsesUndatedBatchLast` |
| Expired batches skipped | `testFefoSkipsExpiredBatches` |
| Expiry inclusive of the expiry date | `testExpiryIsInclusiveOfTheExpiryDateItself` |
| Shortfall reported when stock runs out | `testFefoReportsShortfallWhenStockRunsOut` |
| Shortfall is zero when fully allocated | `testShortfallIsZeroWhenFullyAllocated` |
| Zero/negative qty allocates nothing | `testAllocateZeroOrNegativeReturnsNothing` |
| Scoped to one item | `testFefoIsScopedToOneItem` |
| Write-off marks and totals | `testWriteOffExpiredMarksAndTotals` |
| Written-off batch not allocated after | `testExpiredBatchIsNotAllocatedAfterWriteOff` |
| Undated batch never expires | `testNeverExpiresWhenNoExpiryRecorded` |
| Fractional quantity allowed | `testRegisterAllowsFractionalQuantity` |

## Traceability

Tests : `tests/Unit/BatchNumberServiceTest.php`
@BABOK Need-tag: SN-FEFO - BABOK 7.4 (information analysis: order by expiry)
@BABOK Need-tag: SN-EMPTY-ACCEPT - BABOK 7.1 (zero qty = declared no-op)
