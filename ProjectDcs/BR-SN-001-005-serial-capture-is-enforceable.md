# BR-SN-001-005 — Serial capture must be enforceable at the point of picking

@BABOK Related: CTX-SN-001
Status : Approved — resolver implemented, UI capture still to build
Module : ksf_FA_SerialNumber

## Business Need

Serial control is worthless if a serial-controlled line can be picked with no
serial recorded. A machine leaves the warehouse with no identity attached, the
warranty can never be validated, and the loss surfaces months later at audit —
by which point the unit is in a customer's building and untraceable.

## Business Requirement

The system **must** be able to tell a serial-controlled line from an ordinary one
at the moment of scanning, **must** refuse to treat the first as pickable
without a serial, and **must** resolve a scanned serial to its SKU and its exact
pick face.

FA core provides **no cart-line validation hook** (see AGENTS_ARCH.md), so the
scan is the only point at which this can be enforced. That is why the resolver
exists as a capability other modules call rather than as an internal detail.

## Status

- Resolver: implemented (`scan_resolve` capability).
- Custom delivery/invoice capture UI: **not implemented**. This is the remaining
  half — the resolver can say "a serial is mandatory", but something has to act
  on that at the point of picking.
