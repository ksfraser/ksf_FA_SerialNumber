<?php
/**
 * @BABOK Related: FR-SN-003-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;
use ksfraser\FrontAccounting\SerialNumber\Exception\InvalidSerialStateException;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryOwnershipRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * A serial's position is a FULL scoped pick face, not a bare shelf id.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.1.0
 */
class PickFaceTest extends TestCase
{
    /** @var InMemorySerialRepository */
    private $repo;

    /** @var SerialNumberService */
    private $service;

    protected function setUp(): void
    {
        $this->repo = new InMemorySerialRepository();
        $this->service = new SerialNumberService(
            $this->repo,
            '2026-03-01 09:00:00',
            new InMemoryOwnershipRepository()
        );
    }

    public function testAUnitWithNoLocationIsLegitimate(): void
    {
        // Not yet put away is a real state, not an error.
        $serial = $this->service->register('SN-200', 'WIDGET');

        $this->assertNull($serial->locCode);
        $this->assertNull($serial->pickFace());
        $this->assertFalse($serial->isShelved());
        $this->assertCount(0, $this->service->history('SN-200'));
    }

    public function testTheBinIsPartOfThePickFace(): void
    {
        $serial = $this->service->register('SN-201', 'WIDGET', array(
            'locCode' => 'MAIN',
            'aisleId' => 4,
            'shelfId' => 2,
            'binId'   => 3,
        ));

        $face = $serial->pickFace();

        $this->assertSame(
            array('loc_code' => 'MAIN', 'aisle_id' => 4, 'shelf_id' => 2, 'bin_id' => 3),
            $face
        );
        $this->assertTrue($serial->isShelved());
    }

    public function testAShelfWithoutAnAisleIsRefused(): void
    {
        // The failure that motivated the whole change: shelf 2 of aisle 4 and
        // shelf 2 of aisle 9 are different shelves, so a bare shelf id is
        // ambiguous and must never be persisted.
        $this->expectException(InvalidSerialStateException::class);
        $this->expectExceptionMessage('together');

        $this->service->register('SN-202', 'WIDGET', array(
            'locCode' => 'MAIN',
            'shelfId' => 2,
        ));
    }

    public function testAnAisleWithoutABinIsRefused(): void
    {
        $this->expectException(InvalidSerialStateException::class);
        $this->expectExceptionMessage('got 3 of 4');

        $this->service->register('SN-203', 'WIDGET', array(
            'locCode' => 'MAIN',
            'aisleId' => 4,
            'shelfId' => 2,
        ));
    }

    public function testABinWithNoLocationIsRefused(): void
    {
        $this->expectException(InvalidSerialStateException::class);

        $this->service->register('SN-204', 'WIDGET', array('binId' => 3));
    }

    public function testAssignLocationRefusesAPartialFace(): void
    {
        $this->service->register('SN-205', 'WIDGET');

        $this->expectException(InvalidSerialStateException::class);

        $this->service->assignLocation('SN-205', 'MAIN', 4, 2);
    }

    public function testAssignLocationAcceptsTheFullFace(): void
    {
        $this->service->register('SN-206', 'WIDGET');

        $serial = $this->service->assignLocation('SN-206', 'MAIN', 4, 2, 3);

        $this->assertSame(4, $serial->aisleId);
        $this->assertSame(2, $serial->shelfId);
        $this->assertSame(3, $serial->binId);
    }

    public function testGoodsInLandsOnTheReservedUnassignedFace(): void
    {
        // The reserved face is a real bin like any other, so a received unit is
        // genuinely shelved -- just not yet put away.
        $serial = $this->service->register('SN-207', 'WIDGET', array(
            'locCode' => 'MAIN',
            'aisleId' => 999,
            'shelfId' => 999,
            'binId'   => 999,
        ));

        $this->assertTrue($serial->isShelved());
        $this->assertSame(999, $serial->binId);
    }

    public function testPutAwayMovesFromTheReservedFaceToARealBin(): void
    {
        $this->service->register('SN-208', 'WIDGET', array(
            'locCode' => 'MAIN',
            'aisleId' => 999,
            'shelfId' => 999,
            'binId'   => 999,
        ));

        $this->service->move('SN-208', 'MAIN', 4, 2, 3, 'put-away');

        $serial = $this->service->get('SN-208');

        $this->assertSame(4, $serial->aisleId);
        $this->assertSame(3, $serial->binId);

        $history = $this->service->history('SN-208');
        $this->assertSame('put-away', $history[0]->reason);
        $this->assertSame(999, $history[0]->fromBinId);
        $this->assertSame(3, $history[0]->toBinId);
    }

    /**
     * The join hazard: identical bin numbers on different faces must not match.
     */
    public function testTheSameBinNumberOnDifferentShelvesIsADifferentFace(): void
    {
        $this->service->register('SN-209', 'WIDGET', array(
            'locCode' => 'MAIN', 'aisleId' => 4, 'shelfId' => 2, 'binId' => 3,
        ));
        $this->service->register('SN-210', 'WIDGET', array(
            'locCode' => 'MAIN', 'aisleId' => 9, 'shelfId' => 2, 'binId' => 3,
        ));

        $onAisle4 = $this->repo->findByFace('MAIN', 4, 2, 3);
        $onAisle9 = $this->repo->findByFace('MAIN', 9, 2, 3);

        $this->assertCount(1, $onAisle4);
        $this->assertCount(1, $onAisle9);
        $this->assertSame('SN-209', $onAisle4[0]->serialNo);
        $this->assertSame('SN-210', $onAisle9[0]->serialNo);
    }

    public function testTheSameFaceInTwoWarehousesIsTwoFaces(): void
    {
        $this->service->register('SN-211', 'WIDGET', array(
            'locCode' => 'MAIN', 'aisleId' => 4, 'shelfId' => 2, 'binId' => 3,
        ));
        $this->service->register('SN-212', 'WIDGET', array(
            'locCode' => 'DEPOT', 'aisleId' => 4, 'shelfId' => 2, 'binId' => 3,
        ));

        $this->assertCount(1, $this->repo->findByFace('MAIN', 4, 2, 3));
        $this->assertCount(1, $this->repo->findByFace('DEPOT', 4, 2, 3));
    }

    public function testFindByFaceReturnsEveryUnitOnThatFace(): void
    {
        $this->service->register('SN-213', 'WIDGET', array(
            'locCode' => 'MAIN', 'aisleId' => 4, 'shelfId' => 2, 'binId' => 3,
        ));
        $this->service->register('SN-214', 'GADGET', array(
            'locCode' => 'MAIN', 'aisleId' => 4, 'shelfId' => 2, 'binId' => 3,
        ));

        $this->assertCount(2, $this->repo->findByFace('MAIN', 4, 2, 3));
    }

    public function testRetiringClearsTheWholeFace(): void
    {
        $this->service->register('SN-215', 'WIDGET', array(
            'locCode' => 'MAIN', 'aisleId' => 4, 'shelfId' => 2, 'binId' => 3,
        ));

        $retired = $this->service->retire('SN-215');

        $this->assertNull($retired->locCode);
        $this->assertNull($retired->aisleId, 'a retired unit is not on any aisle');
        $this->assertNull($retired->shelfId);
        $this->assertNull($retired->binId);
        $this->assertNull($retired->pickFace());

        // The trail keeps where it used to be.
        $history = $this->service->history('SN-215');
        $this->assertSame(3, $history[0]->fromBinId);
        $this->assertNull($history[0]->toBinId);
    }

    public function testToArrayCarriesTheWholeFace(): void
    {
        $serial = new SerialNumberDto('SN-216', 'WIDGET');
        $serial->aisleId = 4;
        $serial->shelfId = 2;
        $serial->binId = 3;

        $row = $serial->toArray();

        $this->assertSame(4, $row['aisle_id']);
        $this->assertSame(2, $row['shelf_id']);
        $this->assertSame(3, $row['bin_id']);
    }

    public function testToArrayOnABareDtoDoesNotFatal(): void
    {
        // Untyped properties with defaults: a typed property without one would be
        // uninitialised and reading it throws (AGENTS_ARCH.md §1).
        $row = (new SerialNumberDto())->toArray();

        $this->assertNull($row['bin_id']);
        $this->assertNull($row['aisle_id']);
    }
}