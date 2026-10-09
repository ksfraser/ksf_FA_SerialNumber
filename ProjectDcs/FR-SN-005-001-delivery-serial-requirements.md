# FR-SN-005-001 — A serial-controlled line needs one serial per unit

@BABOK Related: BR-SN-001-005
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

`0_sales_order_details` holds a quantity and a stock code. Nothing in FA ties
"one serial" to "one unit of quantity", so a line for three machines reads
identically to a line for three washers. FA has **no cart-line validation hook**
(AGENTS_ARCH.md), so this rule has to be expressed and enforced by the module.

## Requirement

`DeliverySerialCapture`

- `requirements(array $lines)` turns order lines into
  `CartSerialRequirement[]` keyed by item code.
  - Only items in `0_ksf_serial_control` are controlled.
  - **Repeated lines of the same item aggregate** — two lines of 1 each need two
    serials, not one per line.
  - Lines with no `stock_id` are skipped.
  - An **uncontrolled item needs zero serials**; `shortfall()` returns 0 for it,
    not its quantity.
- `assign(array $requirements, string $itemCode, string $code)` accepts one
  scanned code against one line, and **refuses**:
  - an item code (explained: "That is the item code. Scan the serial number.")
  - a serial belonging to a **different** item
  - a serial **already on this line**
  - a serial **already on another line of the same order** — two customers cannot
    both own one unit
  - a serial that is `retired` or `installed` — neither is stock
  - a line that is already full
  - an unknown code
  - a line that is not serial-controlled
  - a line that does not exist on the order (an `InvalidArgumentException`,
    because that is a plumbing error, not a scan error)
- `unassign()` removes a mis-scan.
- `isReady()` is the gate: false means the delivery is not safe to commit.
- `outstanding()` / `summarise()` produce the picker's to-do list.

Capture does **not** move the serials — that is FR-SN-005-002. Splitting them is
what stops a serial being sold for a delivery that then failed to save.

## Acceptance

Covered by `tests/Unit/DeliverySerialCaptureTest.php` (23 tests), including:

| Criterion | Test |
|---|---|
| Only controlled items are marked | `testRequirementsMarkControlledItemsOnly` |
| An uncontrolled line is always satisfied and has no shortfall | `testAnUncontrolledLineIsAlwaysSatisfied` |
| One serial per unit satisfies the line | `testOneSerialPerUnitSatisfiesTheLine` |
| Scanning an item code is explained | `testScanningAnItemCodeIsExplainedNotJustRejected` |
| A serial for the wrong item is refused | `testASerialForTheWrongItemIsRefused` |
| The same serial cannot be scanned twice | `testScanningTheSameSerialTwiceIsRefused` |
| A serial cannot satisfy two lines | `testASerialCannotBeClaimedByADifferentControlledLine` |
| A retired serial cannot be captured | `testARetiredSerialCannotBeCaptured` |
| A full line refuses more | `testALineThatIsAlreadyFullRefusesMore` |
| Repeated lines aggregate | `testRepeatedLinesOfOneItemAggregateQuantity` |

## Traceability

BRs : BR-SN-001-005
Implements : `CartSerialRequirement`, `DeliverySerialCapture`.

## Note on the "already on another line" check

`assignedToOtherLine()` only sees in-memory assignments, so it catches a
double-scan within one capture session. Two *different* orders both claiming one
serial cannot be detected here — only the commit step knows the other document's
number. That residual gap is stated rather than papered over.
