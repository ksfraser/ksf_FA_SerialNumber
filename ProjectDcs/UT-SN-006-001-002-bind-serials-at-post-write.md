# UT-SN-006-001-002 — Serials bind at post-write

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-006-001 (binding half)

See FR-SN-005-002 for the requirement and
`tests/Unit/DeliverySerialCommitTest.php` for the tests.

The `db_postwrite` hook is wired to `ST_CUSTDELIVERY` only: an invoice raised from
an already-delivered order has nothing left to capture, because the serials were
bound at delivery.
