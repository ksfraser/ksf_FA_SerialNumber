# AGENTS.local.md — ksf_FA_SerialNumber

Repo-specific decisions for this module. Shared conventions live in the shared
`AGENTS_ARCH.md` (hardlinked).

## Identity

- FA module: dir `ksf_FA_SerialNumber`, hooks class `hooks_ksf_FA_SerialNumber`
- Namespace: `ksfraser\FrontAccounting\SerialNumber\` (PSR-4 `src/`)
- Security section: `SS_ksf_FA_SerialNumber` = **156 << 8**
- Tables (literal `0_`, per FA's installer): `0_ksf_serial_numbers`,
  `0_ksf_serial_location_log`, `0_ksf_batch_numbers`
- PHP floor **7.3** — no typed properties, no `match`, no arrow functions, no
  `?->`. `ModuleConventionsTest` enforces this. The container runs 7.4 and the
  host 8.1, so `composer.json` pins `config.platform.php = 7.4.33`.

## Scope — what this module owns, and what it must not

**Owns:** serial and batch *identity*; where each unit is; the location audit
trail; warranty *facts* (start/end dates, cover queries, extension).

**Does NOT own:**

| Concern | Owner |
|---|---|
| aisle → bin → shelf hierarchy | `ksf_FA_Warehouse` |
| aggregate stock on hand, over/short, holding tank, transfers | `ksf_FA_Warehouse` + `ksf_FA_InventoryCount` |
| RMA cases, claims, liabilities | `ksf_FA_WarrantyManagement` |
| purchasing / vendor pricing | FA core |

`shelf_id` is stored as an **opaque integer reference**. This module never reads
`0_ksf_wh_*` and never resolves a shelf to an aisle or bin — that is
warehouse's job, and two modules resolving the same hierarchy differently is how
inventory drifts.

`move()` records where a serial went; it deliberately does **not** move aggregate
FA stock. Stock movement goes through warehouse so serial position and
`0_stock_moves` cannot diverge through two independent writers.

The original `ksf_Inventory` (archived 2026-08-22, read-only) carried a
competing `inventory_warehouse_locations` hierarchy. That duplication is why
this is a new module rather than a revival.

## Design decisions worth remembering

- **The warranty clock runs from `installed_date`, not `sold_date`.** A unit that
  sat in a warehouse for three months before installation should not have burned
  three months of cover.
- **Warranty end date is inclusive.** A unit expiring 2026-02-01 is covered *on*
  2026-02-01, which is why `daysRemaining()` returns 1 on the final day, not 0.
- **`extend()` adds to remaining cover** when the warranty is still live, and
  only restarts from the reference date when it has already expired — a repair
  performed a year late must not produce a warranty already in the past.
- **Status gates warranty.** `isCovered()` requires `installed`. A returned or
  scrapped unit has no cover even if a `warranty_end` is still sitting on the
  row.
- **Lifecycle transitions are a closed table** in `SerialNumberService`. An
  installed unit cannot jump back to `available` by a bare status write; it must
  go through `returnSerial()`, which clears `sold_to` and restarts the clock.
  `retired` is terminal and idempotent.
- **Every state change writes a location-log row.** That is what makes "where is
  this unit now?" answerable and lets serials be reconciled against aggregate
  stock.
- **Batch quantities are floats, not ints.** Weighed goods are fractional and an
  int column silently truncates the discrepancy away.
- **FEFO consumes undated batches LAST.** MySQL sorts `NULL` first ascending, so
  both the FA adapter and the in-memory fake use an explicit `CASE` to push them
  to the back — a batch with no expiry never expires and is the correct fallback.
- **Expiry is inclusive** of the expiry date itself, matching how "use before"
  reads on packaging.
- **`batch_allocate` returns a plan and does not decrement.** A partial
  allocation is a commercial decision (allow the sale short, or block it), so the
  caller decides whether to commit. `shortfall()` makes the gap explicit.

## Capabilities

Read-only capabilities answer via `respondToCapabilityRequest` and are safe to
broadcast (`hook_invoke_all`); every returned row carries `_module` and
`_entity` so consumers can tell providers apart:

`serial_lookup`, `serial_at_location`, `serial_at_shelf`, `serial_history`,
`warranty_cover`, `batch_allocate`

Write capabilities — `serial_register`, `serial_move`, `serial_sell`,
`serial_return`, `serial_retire`, `warranty_extend` — are advertised but
deliberately **not** reachable through a broadcast. Two modules moving the same
serial must not race. Callers use `hook_invoke_first`, or call directly.

`ksf_FA_WarrantyManagement` should consume `warranty_cover` rather than reading
`0_ksf_serial_numbers`, so there is one authority for warranty dates.

## Gotchas inherited from the ecosystem

- **`{{MDB}}` and `{TB_PREF}` are not substituted** by FA's `db_import()` —
  only a literal `0_` is. `ksf_Inventory` shipped `{{MDB}}` and its tables were
  never created. Guarded by `ModuleConventionsTest`.
- **A classmap hides one-file-many-classes.** `ksf_Inventory` had three classes
  in one file; under PSR-4 they became unresolvable. One class per file here.
- **When writing a token-stripping guard, emit `$token[1]` for every non-comment
  array token.** A stray `continue` drops all `T_STRING` tokens so the regex
  never matches a function name and the guard passes vacuously. Two guards in
  `ksf_FA_Square` were broken exactly that way.
- **Guards must ignore their own documentation.** The SQL guard here initially
  failed on the file's header comment, which names `{TB_PREF}` precisely to
  explain why it is wrong. Strip `--` comments before checking.

## Open items

- No UI beyond the search/register/move page (`SerialNumbers.php` +
  `src/Ui/PageController.php`). Warranty and batch screens are not built.
- No reconciliation report comparing serial counts per shelf against
  `0_stock_moves` — `ksf_FA_DataIntegrity` is the natural home once warehouse
  can answer shelf-level counts.
- `warranty_cover` needs a caller. `ksf_FA_WarrantyManagement` has an
  `install.sql` that is only a placeholder while its pages query
  `TB_PREF."fa_wm_liability"`, so it is currently non-functional.