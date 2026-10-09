# UT-SN-002-001-002 — The owner is typed, not free text

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-002-001

## Tests

- `OwnershipTest::testAnUnrecognisedOwnerKindIsRefused` — `freetext` is refused.
- `OwnershipTest::testEveryPermittedOwnerKindIsAccepted` — all four kinds work.
- `OwnershipTest::testAnEmptyOwnerReferenceIsRefused`
- `OwnershipTest::testUnitsHeldByRefusesAnUnknownKind`

## Why

The `sold_to` column this replaced was free text that could name a debtor, a
branch, a contact or a person, and nothing validated it — so a typo silently
orphaned an expensive asset. `owner_kind` is a closed set, and each kind resolves
to a real FA record.
