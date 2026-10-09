# UT-SN-002-001-001 — A serial never has two open owners

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-002-001

## Tests

- `OwnershipTest::testAUnitNeverHasTwoOpenOwners`
- `OwnershipTest::testSellingOpensExactlyOnePeriod`
- `OwnershipTest::testAResaleKeepsThePreviousOwnerInTheTrail`

## Why the schema needed a generated column

`UNIQUE (serial_no, owned_to)` does **not** enforce this: a UNIQUE index treats
every NULL as distinct, so it accepts unlimited open rows. Verified against the
live MariaDB 10.11.14 — the naive key allowed two open rows for one serial. The
`current_marker` generated column is 1 only while a row is open, so the unique
key permits exactly one open owner and any number of closed ones.

The in-memory fake enforces the same invariant and throws if a test opens twice,
so a test cannot pass on state production would reject.
