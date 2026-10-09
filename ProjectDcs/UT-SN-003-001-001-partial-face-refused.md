# UT-SN-003-001-001 — A partial pick face is refused

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-003-001

## Tests

- `PickFaceTest::testAShelfWithoutAnAisleIsRefused` — the failure that motivated
  the change: shelf 2 of aisle 4 and shelf 2 of aisle 9 are different shelves.
- `PickFaceTest::testAnAisleWithoutABinIsRefused`
- `PickFaceTest::testABinWithNoLocationIsRefused`
- `PickFaceTest::testAssignLocationRefusesAPartialFace`
- `PickFaceTest::testAUnitWithNoLocationIsLegitimate` — **absent** is fine; only
  *partial* is refused. A unit not yet put away is a real state.
