# BR-SN-001-004 — Dated batch stock must be issued oldest-first

@BABOK Related: CTX-SN-001
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Business Need

Perishable and dated stock — shelf-life batteries, consumables with lot numbers,
anything with an expiry — must be issued oldest first, or the business writes
off value it has already paid for. And when dated stock cannot cover an order,
the gap must be **reported** rather than silently truncated, because a silent
truncation is discovered on the warehouse floor instead of at the desk.

## Business Requirement

The system **must** allocate batch stock **FEFO** (first expired, first out),
with expiry inclusive of the expiry date itself, holding undated batches back as
the last-resort fallback, skipping expired batches, never crossing item codes,
and **must** report any shortfall against the requested quantity. Expired batches
must be write-off-able and must not be allocated afterwards.

## Implemented by

FR-SN-001-004
