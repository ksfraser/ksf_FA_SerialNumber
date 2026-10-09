# UT-SN-004-002-001 — A scan resolves to a discriminated outcome

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-004-002

## Tests

`tests/Unit/ScanResolverTest.php` — 18 tests covering all four kinds and the
pickability rules.

## Why a union and not a boolean

The three outcomes need different handling: an ordinary item can be picked, a
serial-controlled item CANNOT be picked without a serial, and a serial resolves
to an SKU *and* a position — a different shape entirely. Collapsing them into a
boolean is how a machine gets picked with no serial recorded and the loss only
surfaces at audit.
