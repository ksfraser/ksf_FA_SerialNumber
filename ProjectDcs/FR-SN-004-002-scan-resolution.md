# FR-SN-004-002 — A scanned code resolves to a discriminated outcome

@BABOK Related: BR-SN-001-005
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

A warehouse scanner produces a string and nothing else. It may be a serial, an
item code, or a barcode standing for an item. The caller needs to know which,
and only three answers are useful:

1. it is a **serial** → here is the SKU and here is the bin
2. it is an **ordinary item** → fine, pick it
3. it is a **serial-controlled item with no serial scanned** → NOT fine

## Requirement

`ScanResolver::resolve(string $code): ScanResolution`

`ScanResolution` is a discriminated union over four closed kinds, because PHP 7.3
has no union types:

| Kind | Meaning | `isPickable()` |
|---|---|---|
| `item` | plain stock item | true |
| `item_requires_serial` | controlled, no serial scanned | **false** |
| `serial` | resolves to SKU + pick face | true only when available/reserved |
| `unknown` | nothing matches | false |

Rules:

- **Serials are resolved first.** A serial is the more specific fact, so an item
  code that collides with a serial resolves to the serial.
- An **unknown** code is `unknown`, never `item_requires_serial` — we do not know
  what it is, so we must not claim it needs a serial.
- **Never throws** for an unrecognised code. A damaged label or a code from
  another system is a normal event; throwing would put a stack trace on the shop
  floor instead of a message.
- `isPickable()` is false for a **retired** unit wherever it happens to sit, and
  false for an **installed** one — neither is stock.
- `describe()` returns a one-line explanation suitable for a UI message.
- Item lookup is an **injected seam** (`ItemLookupInterface`), not a
  `function_exists('get_stock_id')` guard: such a guard makes the resolver
  report every code as unknown under test, so the mock hides the behaviour
  instead of the code being right.

## Acceptance

| Criterion | Test |
|---|---|
| Ordinary item → `item` | `testAnOrdinaryItemResolvesToItem` |
| Controlled item → `item_requires_serial`, not pickable | `testASerialControlledItemResolvesToItemRequiresSerial` |
| Serial → SKU + full pick face | `testAResolvedSerialCarriesTheSkuAndTheBin` |
| Unknown code → `unknown`, not needs-serial | `testAnUnknownCodeIsUnknownNotNeedsSerial` |
| Empty scan → `unknown` | `testAnEmptyScanIsUnknown` |
| Scans are trimmed | `testScansAreTrimmed` |
| A serial wins a collision with an item code | `testASerialWinsOverACollidingItemCode` |
| A retired serial is not pickable | `testARetiredSerialIsNotPickable` |
| A reserved serial is pickable | `testAReservedSerialIsStillPickable` |
| An unshelved serial says so | `testAnUnshelvedSerialSaysSo` |
| Item default warranty reaches the resolution | `testWarrantyDaysComeFromTheItemDefault` |

Tests : `tests/Unit/ScanResolverTest.php`

## Traceability

BRs : BR-SN-001-005
UTs : UT-SN-004-002-001
Implemented by `ScanResolution`, `ScanResolver`, `ItemLookupInterface`,
`FaItemLookup`, and the `scan_resolve` capability in `hooks.php`.

## Known gap

The **capture UI** is not built. The resolver can report that a serial is
mandatory, but nothing yet acts on it at the point of picking.
