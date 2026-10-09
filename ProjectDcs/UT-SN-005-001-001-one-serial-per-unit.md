# UT-SN-005-001-001 — A serial-controlled line needs one serial per unit

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-005-001

## Tests

`tests/Unit/DeliverySerialCaptureTest.php` — 23 tests.

## The rule that FA cannot express

`0_sales_order_details` has a quantity and a stock code, and nothing ties "one
serial" to "one unit of quantity". `shortfall()` supplies that missing link, and
returns 0 for an uncontrolled item — a test
(`testAnUncontrolledLineIsAlwaysSatisfied`) caught an earlier version that
returned the raw quantity, which would have told the picker to scan serials that
do not exist.
