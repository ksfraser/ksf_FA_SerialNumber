# BR-SN-001 — Serialised goods must be traceable end to end

@BABOK Related: BABOK 7 (Requirements Analysis) — traceability of a physical unit
Status : Approved — partially implemented
Module : ksf_FA_SerialNumber

## Business Need

A serialised unit (a machine, a phone, a vehicle, a licence-key-bound device) is
**not interchangeable** with its siblings. It has its own identity, its own
warranty, and it may end up belonging to a customer who has since changed
company or address.

The business therefore needs to answer four questions at any moment:

1. **Where is it?** — which location, and since when.
2. **Whose is it?** — not just "sold", but *which customer, from when, and
   after which resale*.
3. **Is it still under warranty?** — as at a given date, not "is there a date
   in the row".
4. **What happened to it?** — a full audit trail, because these units are the
   expensive stock.

## Business Requirements

| # | Requirement |
|---|---|
| BR-SN-001-001 | A serial has a controlled lifecycle: available → reserved → installed → returned/retired. Illegal transitions must be refused. |
| BR-SN-001-002 | Ownership changes over time. A unit can change hands. History is retained. |
| BR-SN-001-003 | Warranty is a fact derived from install date plus duration, evaluated as at a date. |
| BR-SN-001-004 | Batch/expiry stock issues **FEFO** (first expired, first out), with the shortfall reported when stock runs out. |

## Status against implementation

Implemented: BR-SN-001-001, -003, -004.
**Not implemented: BR-SN-001-002.** Ownership is currently a single mutable
`sold_to` column on `0_ksf_serial_numbers`, which cannot represent a unit that
changed hands. See `FR-SN-002-001` for the required xref and the reason.
