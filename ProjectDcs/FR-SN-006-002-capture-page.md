# FR-SN-006-002 — The picker has a page to scan on

@BABOK Related: BR-SN-001-005, FR-SN-006-001
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Need

A redirect target has to be a usable screen. The picker arrives here, scans each
serial, and is returned to the delivery when the order is satisfied.

## Requirement

`serial_capture.php`

- Reads the order's lines from `get_sales_order_details($order_no, ST_SALESORDER)`
  — **note the column is `quantity`, not `qty`** — and replays the session.
- One scan box. A scan resolves through `ScanResolver`, so scanning the item code
  by mistake produces a message rather than a silent failure.
- Shows each line's remaining shortfall and the serials already on it.
- Mis-scans are removed with a checkbox per serial; the value is `item|serial`
  so one submit clears several lines.
- When nothing is outstanding, offers `sales_order_entry.php?NewDelivery=<n>` to
  carry on.
- When something is outstanding, warns that the delivery cannot be saved.

### FA helpers were verified, not assumed

Every UI helper used was checked against this FA tree. `TBL_TYPE_LST`,
`note_row()` and `_qty()` **do not exist in 2.4.3** and are deliberately absent —
using them would be a fatal on page load. Also note `table_header()` opens *and*
closes its own row, so no `end_row()` follows it.

The list is wrapped in a single form rather than one form per serial: FA's
`start_form()` emits the `<form>` tag itself, so nested forms would be invalid
HTML and browsers silently drop the inner ones. The checkbox approach also needs
no JavaScript.

## Acceptance

Not unit tested — it is a page shell. What is tested is everything it delegates
to: `DeliverySerialGateTest`, `DeliverySerialCaptureTest`, `ScanResolverTest`.
Static verification performed: PHP lint, every UI helper confirmed to exist in the
FA tree, `start_row`/`end_row` and `start_form`/`end_form` balanced 2/2 and 3/3.

## Traceability

BRs : BR-SN-001-005
Implements : the UI half of FR-SN-006-001.
