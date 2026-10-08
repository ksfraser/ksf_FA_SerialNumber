# ksf_FA_SerialNumber

FrontAccounting module for serial number and batch traceability.

## What it does

- **Serial numbers** — register, reserve, move, sell/install, return, retire.
  A closed lifecycle table means an installed unit cannot silently drop back
  into stock; it has to come back through `returnSerial()`.
- **Location audit trail** — every state change writes a row to
  `0_ksf_serial_location_log`, so "where is this unit now?" is answerable and
  serials can be reconciled against aggregate stock.
- **Batches** — fractional quantities, FEFO (first-expired, first-out)
  allocation, expiry write-off. Undated batches are consumed last.
- **Warranty** — cover queries, days remaining, extension, and an
  expiring-soon worklist. The clock runs from installation, not sale.

## Boundaries

This module owns serial/batch identity, location and warranty *facts* only.

| Concern | Owner |
|---|---|
| aisle → bin → shelf hierarchy | `ksf_FA_Warehouse` |
| aggregate stock, over/short, holding tank | `ksf_FA_Warehouse` + `ksf_FA_InventoryCount` |
| RMA, claims, liabilities | `ksf_FA_WarrantyManagement` |

`shelf_id` is stored as an opaque reference; this module never reads the
warehouse tables. `move()` records a serial's new location but does not move
aggregate FA stock — that is warehouse's job, so the two cannot diverge.

See `AGENTS.local.md` for the full rationale.

## Install

1. `composer install` in this directory.
2. Copy or symlink into FA's `modules/` as `ksf_FA_SerialNumber`.
3. Activate the extension — `sql/install.sql` is applied automatically.

Requires PHP 7.3+ (no typed properties, `match`, arrow functions or `?->`).
The container runs PHP 7.4; `composer.json` pins `config.platform.php` so vendor
resolves for the container rather than the host's PHP.

## Tests

```bash
php vendor/bin/phpunit
```

61 tests. The suite covers the lifecycle transitions, warranty boundaries
(inclusive end date, status gating, extension semantics), FEFO ordering, and
`ModuleConventionsTest` guards the things that break silently: the literal `0_`
table prefix, the namespace, the PSR-4 mapping, the platform pin, the security
section number, and PHP 7.3 compatibility.
