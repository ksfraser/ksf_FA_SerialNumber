# UT-SN-005-002-001 — Serials bind to the delivery at post-write

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-005-002

## Tests

`tests/Unit/DeliverySerialCommitTest.php` — 8 tests.

## Why the commit is separate from capture

Capture holds serials in memory. If capture also sold them, a serial would be
marked sold for a delivery that then failed to save. Binding happens only where a
document number exists — `hook_db_postwrite($delivery, ST_CUSTDELIVERY)` at
`sales/includes/db/sales_delivery_db.inc:200`, which is one line before
`commit_transaction()`.
