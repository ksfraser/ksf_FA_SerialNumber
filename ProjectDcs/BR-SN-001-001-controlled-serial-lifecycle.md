# BR-SN-001-001 — A serial has a controlled lifecycle

@BABOK Related: CTX-SN-001
Status : Approved — implemented
Module : ksf_FA_SerialNumber

## Business Need

A serialised unit is not interchangeable with its siblings: it has its own
identity, its own warranty and its own history. Allowing free-text status
values would let an operator sell a retired unit or put an installed unit
straight back on the shelf, and the warehouse aggregate would then silently
disagree with the movement ledger.

## Business Requirement

The system **must** enforce, not merely document, the lifecycle
`available → reserved → installed → returned/retired`. Illegal transitions
**must** be refused with a typed exception. A retired unit is terminal.

## Implemented by

FR-SN-001-001
