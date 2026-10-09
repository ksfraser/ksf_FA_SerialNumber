# BR-SN-001-002 — Ownership changes over time and history is retained

@BABOK Related: CTX-SN-001
Status : **REQUIRED, NOT IMPLEMENTED**
Module : ksf_FA_SerialNumber

## Business Need

Serialised goods change hands. A machine sold in 2024 may be sold on to another
buyer in 2026, and that buyer will ask for the warranty and service history.
Recording only the *latest* owner destroys the resale trail and leaves the
business unable to answer "who was entitled to cover when".

## Business Requirement

The system **must** record ownership as an append-only history of typed owners
with effective dates, **must** retain every transfer, and **must** guarantee at
most one current owner per serial. Warranty entitlement must be answerable as an
interval against a specific owner.

## Status

Not implemented — `0_ksf_serial_numbers.sold_to` is a single mutable column.
See FR-SN-002-001 for the required design and migration.
