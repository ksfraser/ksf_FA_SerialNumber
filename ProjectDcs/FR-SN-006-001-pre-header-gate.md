# FR-SN-006-001 — A delivery is gated while a serial is missing

@BABOK Related: BR-SN-001-005, FR-SN-005-001
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

Serial capture (FR-SN-005-001) can report that a serial is missing, but
something has to act on it at the point of picking. Without that, serial control
is advisory and a machine still leaves the building with no identity recorded.

## Why a redirect is the only enforcement available

FA offers no way to veto a delivery from a hook:

- there is **no cart-line validation hook**, and
- `hook_db_postwrite` fires only *after* the document is written, and
- **FA ignores `db_prewrite` return values**.

What is left is `hook_invoke_all('pre_header')` at
`includes/page/header.inc:132`. It runs before any output — the
`headers_sent()` check is a few lines *below* it — so redirecting from there is
the only point at which the delivery can be diverted.

The DI cart is created at `sales_order_entry.php:62-69` and `page()` is called at
line 102, so the cart is **already populated** when `pre_header` fires and the
requirement set can be computed from it.

## Requirement

`hooks_ksf_FA_SerialNumber::pre_header(&$args)`

- Returns immediately unless the page is `sales_order_entry.php` and the cart's
  `trans_type` is `ST_CUSTDELIVERY` or `ST_SALESINVOICE`. `pre_header` fires on
  **every** page, so a broader test would either gate unrelated pages or miss this
  one.
- Rebuilds the requirement set from `$cart->get_items()`, replays the serials the
  picker already scanned, and asks `DeliverySerialGate::redirectFor()`.
- On a block: `header('Location: ...'); exit;` — safe because no output has
  happened at that point.

`DeliverySerialGate`

- `shouldBlock()` — the testable rule.
- `redirectFor()` — builds the capture URL, or null when nothing blocks.
- `messageFor()` — the outstanding list.
- `requirementsFromCart()` — reads FA cart lines, which may be objects
  (`stock_id`/`qty`), arrays, or an invoice line carrying a `product` object.
  Lines with no stock id (call-offs, service lines) are skipped.

## Captured serials live in the session

The requirement set is rebuilt on every page render, so scanned serials are stored
per order number in `$_SESSION['ksf_serial_capture'][$order_no][$item_code]` and
**replayed** onto each fresh set. Recording a scan is therefore exactly what
releases the delivery.

## Acceptance

Covered by `tests/Unit/DeliverySerialGateTest.php` (13 tests):

| Criterion | Test |
|---|---|
| An order of ordinary items is not blocked | `testAnOrderWithOnlyOrdinaryItemsIsNotBlocked` |
| A short serial line blocks | `testAnOrderWithAShortSerialLineIsBlocked` |
| Capturing the serial releases the gate | `testTheOrderIsReleasedOnceSerialsAreCaptured` |
| The redirect points at the capture page | `testRedirectPointsAtTheCapturePage` |
| No redirect when nothing blocks | `testThereIsNoRedirectWhenNothingBlocks` |
| Lines without a stock id are skipped | `testLinesWithoutAStockIdAreSkipped` |
| An invoice line holding a product object is understood | `testAnInvoiceLineHoldingAProductObjectIsUnderstood` |
| Zero quantity never blocks | `testZeroQuantityNeverBlocks` |

## Traceability

BRs : BR-SN-001-005
UTs : UT-SN-006-001-001
