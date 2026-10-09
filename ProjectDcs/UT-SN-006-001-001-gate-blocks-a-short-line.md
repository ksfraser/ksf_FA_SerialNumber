# UT-SN-006-001-001 — The gate blocks while a serial is missing

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-006-001

## Tests

`tests/Unit/DeliverySerialGateTest.php` — 13 tests.

## Why the decision lives in a service, not in hooks.php

`pre_header` fires on every page and its only real job is to ask one question:
may this delivery proceed? Putting the rule in `DeliverySerialGate` means it is
covered by tests rather than only by clicking through FA, and leaves `hooks.php`
with the session and `header()` plumbing — one line each.

The gate must be cheap and must never loop, which is why the page check is
narrow: `sales_order_entry.php` only.
