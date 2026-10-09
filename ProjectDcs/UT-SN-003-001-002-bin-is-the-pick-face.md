# UT-SN-003-001-002 — The bin is the pick face, and the key is fully scoped

**Module:** ksf_FA_SerialNumber
**Requirement:** FR-SN-003-001

## Tests

- `PickFaceTest::testTheBinIsPartOfThePickFace`
- `PickFaceTest::testGoodsInLandsOnTheReservedUnassignedFace` — the reserved
  face is a real bin, so a received unit *is* shelved, just not put away.
- `PickFaceTest::testPutAwayMovesFromTheReservedFaceToARealBin`
- `PickFaceTest::testTheSameBinNumberOnDifferentShelvesIsADifferentFace`
- `PickFaceTest::testTheSameFaceInTwoWarehousesIsTwoFaces`
- `PickFaceTest::testFindByFaceReturnsEveryUnitOnThatFace`
- `PickFaceTest::testRetiringClearsTheWholeFace`
