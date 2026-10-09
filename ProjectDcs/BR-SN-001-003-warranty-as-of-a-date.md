# BR-SN-001-003 — Warranty must be answerable as at any date

@BABOK Related: CTX-SN-001
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Business Need

"Is this warranted?" is asked on a date, by a support agent, about a unit sold
years ago that may have had cover extended. The same unit can be covered on one
date and not another, so cover cannot be a boolean column.

## Business Requirement

The system **must** answer warranty as a **derived fact over an interval**,
evaluated as at a supplied date, starting from the install date rather than the
sale date where an install date is recorded, and **must** support extending
cover — including restarting an extension whose cover has already lapsed.

## Implemented by

FR-SN-001-003
