# FR-SN-004-001 — Items are marked as serial-controlled

@BABOK Related: BR-SN-001-005
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

FA's `0_stock_master` has no flag for "this item needs a serial". Its columns are
category, tax, accounts, dimensions, depreciation — nothing about serial
tracking. Without somewhere to record the decision, no resolver can distinguish
an ordinary item from one where a serial is mandatory, and every serial-
controlled line would be picked with no serial.

## Requirement

`0_ksf_serial_control` — one row per controlled item:

| Column | Purpose |
|---|---|
| `item_code` | UNIQUE; the FA stock id |
| `requires_serial` | 1 = controlled |
| `warranty_days` | **Default** cover applied at sale; 0 for none |

Rules:

- An item with **no row is NOT controlled**. Treating absence as "controlled"
  would make every unlisted item demand a serial and nothing could be picked.
- `warranty_days` is a default, not a record. An individual unit's actual cover
  lives in `0_ksf_serial_numbers.warranty_end`.
- `release()` stops NEW movements demanding a serial; existing units keep their
  serials and their history.

## Acceptance

| Criterion | Where verified |
|---|---|
| No row means not controlled | `ScanResolverTest::testAnOrdinaryItemResolvesToItem` |
| A controlled item demands a serial | `ScanResolverTest::testASerialControlledItemResolvesToItemRequiresSerial` |
| Release stops the demand | `ScanResolverTest::testAnItemNeverNeedsASerialOnceReleased` |
| Default warranty reaches the resolver | `ScanResolverTest::testWarrantyDaysComeFromTheItemDefault` |
| Table installs on the target server | executed against live MariaDB 10.11.14 in a scratch database |

## Traceability

BRs : BR-SN-001-005
Implements : `ItemControlRepositoryInterface`, `FaItemControlRepository`,
InMemory fake, `scan_control` capability.
