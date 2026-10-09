# FR-SN-002-001 — Ownership history is an xref, not a mutable column

@BABOK Related: BR-SN-001-002
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

Serialised goods change hands. A machine is sold to a customer in 2024; that
customer is sold on to another buyer in 2026; the buyer wants the warranty
history and the service record. A single `sold_to` column answers only the
**last** sale and destroys the resale trail.

Worse, `sold_to` is a bare `VARCHAR(64)` with **no referential integrity**: it
is a free-text string that can name a debtor, a branch, a contact or a person,
and nothing validates it. A typo silently orphans a serialised asset.

## Requirement

Replace `0_ksf_serial_numbers.sold_to` / `sold_date` with an **append-only
ownership xref**:

```
0_ksf_serial_ownership
  id            INT(11) PK AUTO_INCREMENT
  serial_no     VARCHAR(64)   NOT NULL   -- FK to 0_ksf_serial_numbers
  owner_kind    ENUM('debtor','branch','contact','person') NOT NULL
  owner_ref     VARCHAR(64)   NOT NULL   -- the id within that kind
  owned_from    DATE          NOT NULL
  owned_to      DATE          NULL       -- NULL = current owner
  note          VARCHAR(255)  NULL
  UNIQUE KEY uniq_current (serial_no, owned_from)
  KEY idx_owner (owner_kind, owner_ref)
```

Rules:

- At most **one open** (`owned_to IS NULL`) row per serial. Enforce in the
  service, and close the previous row in the same transaction that opens the
  new one — never two open rows.
- `owner_kind` is a closed set. This is what stops the free-text problem: the
  owner is typed, and each kind resolves to a real FA record
  (`debtors`, `branches`, or the CRM contact/person tables).
- Contact linkage follows the existing `0_ksf_crm_contacts` /
  `add_crm_contact()` pattern rather than inventing a new address table.
- Resale **appends**; it never overwrites. Warranty entitlement is then a
  question about an interval against a specific owner, not about a column.

## Why not keep sold_to

It cannot represent the case the business actually has (resale), and it has no
referential integrity. Keeping both would create two sources of truth for
ownership, which is worse than either alone.

## Migration

Existing `sold_to` values become one open xref row per serial, with
`owned_from = sold_date` (or `purchase_date` when `sold_date` is null).

## The one-open-row guarantee, and how it is actually enforced

`UNIQUE KEY uniq_current_owner (serial_no, current_marker)` where

```sql
current_marker TINYINT(1) AS (IF(owned_to IS NULL, 1, NULL)) VIRTUAL
```

A plain `UNIQUE (serial_no, owned_to)` **does not work**: a UNIQUE index treats
every NULL as distinct, so it would permit unlimited open rows for one serial.
This was verified against the live MariaDB 10.11.14 before the schema was
written — the naive key accepted a second open row, the generated column
rejected it while still allowing any number of closed rows.

## Acceptance

| Criterion | Test |
|---|---|
| Selling a unit opens exactly one period | `testSellingOpensExactlyOnePeriod` |
| A resale closes the previous period and keeps it on record | `testAResaleKeepsThePreviousOwnerInTheTrail` |
| A serial never has two open owners | `testAUnitNeverHasTwoOpenOwners` |
| History is oldest first | `testHistoryIsOldestFirst` |
| `ownerAsAt` resolves a past owner | `testOwnerAsAtResolvesAPastOwner` |
| Both ends of a period are inclusive | `testOwnerAsAtIncludesBothEndsOfAPeriod` |
| Returning closes the period without deleting it | `testReturningClosesTheOwnershipPeriod` |
| `owner_kind` outside the closed set is refused | `testAnUnrecognisedOwnerKindIsRefused` |
| Every permitted kind is accepted | `testEveryPermittedOwnerKindIsAccepted` |
| An empty owner reference is refused | `testAnEmptyOwnerReferenceIsRefused` |
| `unitsHeldBy` finds a holder's current units | `testUnitsHeldByFindsEverythingOneOwnerCurrentlyHas` |
| A returned unit is no longer listed as held | `testAReturnedUnitIsNoLongerListedAsHeld` |
| Selling with no ownership repository refuses loudly | `testSellingWithoutAnOwnershipRepositoryIsRefusedLoudly` |

Tests : `tests/Unit/OwnershipTest.php`

## Traceability

BRs : BR-SN-001-002 (now satisfied)
UTs : UT-SN-002-001-001, UT-SN-002-001-002
Implements : `OwnershipDto`, `OwnershipRepositoryInterface`,
`FaOwnershipRepository`, and `SerialNumberService::markSold()` /
`currentOwner()` / `ownershipHistory()` / `ownerAsAt()` / `unitsHeldBy()`.
Migration is moot — the module was never activated with the old `sold_to`
column, so the schema is installed clean.

@BABOK Note: `returnSerial()` originally closed the ownership period with a bare
`date('Y-m-d')`, ignoring the service's injected clock. Caught by
`testAResaleKeepsThePreviousOwnerInTheTrail`; fixed by routing it through a
`today()` helper so every date in the service honours the injected clock.
