<?php
/**
 * @BABOK Related: FR-SN-001-001, FR-SN-001-002, FR-SN-002-001, FR-SN-003-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Dto\OwnershipDto;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;
use ksfraser\FrontAccounting\SerialNumber\Exception\DuplicateSerialException;
use ksfraser\FrontAccounting\SerialNumber\Exception\InvalidSerialStateException;
use ksfraser\FrontAccounting\SerialNumber\Exception\SerialNotFoundException;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryOwnershipRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * Serial lifecycle rules.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.0.0
 */
class SerialNumberServiceTest extends TestCase
{
    /** @var InMemorySerialRepository */
    private $repo;

    /** @var InMemoryOwnershipRepository */
    private $owners;

    /** @var SerialNumberService */
    private $service;

    protected function setUp(): void
    {
        $this->repo = new InMemorySerialRepository();
        $this->owners = new InMemoryOwnershipRepository();
        $this->service = new SerialNumberService(
            $this->repo,
            '2026-03-01 09:00:00',
            $this->owners
        );
    }

    /**
     * A complete pick face, for tests that need a shelved unit.
     *
     * @param string $locCode
     * @param int    $aisle
     * @param int    $shelf
     * @param int    $bin
     * @return array
     */
    private function face(string $locCode = 'MAIN', int $aisle = 4, int $shelf = 2, int $bin = 3): array
    {
        return array(
            'locCode' => $locCode,
            'aisleId' => $aisle,
            'shelfId' => $shelf,
            'binId'   => $bin,
        );
    }

    public function testRegisterCreatesAvailableSerial(): void
    {
        $serial = $this->service->register('SN-001', 'WIDGET');

        $this->assertSame('SN-001', $serial->serialNo);
        $this->assertSame('WIDGET', $serial->itemCode);
        $this->assertSame(SerialNumberDto::STATUS_AVAILABLE, $serial->status);
        $this->assertTrue($serial->isOnHand());
        $this->assertGreaterThan(0, $serial->id);
    }

    public function testRegisterTrimsSerialAndRejectsEmpty(): void
    {
        $serial = $this->service->register('  SN-TRIM  ', 'WIDGET');
        $this->assertSame('SN-TRIM', $serial->serialNo);

        $this->expectException(InvalidSerialStateException::class);
        $this->service->register('   ', 'WIDGET');
    }

    public function testRegisterRequiresItemCode(): void
    {
        $this->expectException(InvalidSerialStateException::class);
        $this->service->register('SN-002', '');
    }

    public function testRegisterRejectsDuplicateSerial(): void
    {
        $this->service->register('SN-DUP', 'WIDGET');

        $this->expectException(DuplicateSerialException::class);
        $this->service->register('SN-DUP', 'WIDGET');
    }

    public function testRegisterWithLocationLogsReceipt(): void
    {
        $this->service->register('SN-003', 'WIDGET', $this->face('MAIN', 1, 2, 42));

        $history = $this->service->history('SN-003');
        $this->assertCount(1, $history, 'receiving into a location must be audited');
        $this->assertSame('receipt', $history[0]->reason);
        $this->assertNull($history[0]->fromLocCode);
        $this->assertSame('MAIN', $history[0]->toLocCode);
        $this->assertSame(1, $history[0]->toAisleId);
        $this->assertSame(2, $history[0]->toShelfId);
        $this->assertSame(42, $history[0]->toBinId, 'the bin is the pick face');
    }

    public function testRegisterWithoutLocationLogsNothing(): void
    {
        $this->service->register('SN-004', 'WIDGET');
        $this->assertCount(0, $this->service->history('SN-004'));
    }

    public function testGetUnknownSerialThrows(): void
    {
        $this->expectException(SerialNotFoundException::class);
        $this->service->get('NOPE');
    }

    public function testMoveRecordsBothEnds(): void
    {
        $this->service->register('SN-005', 'WIDGET', $this->face());
        $this->service->move('SN-005', 'DEPOT', 1, 5, 77, 'transfer');

        $serial = $this->service->get('SN-005');
        $this->assertSame('DEPOT', $serial->locCode);
        $this->assertSame(1, $serial->aisleId);
        $this->assertSame(5, $serial->shelfId);
        $this->assertSame(77, $serial->binId, 'the bin is the pick face');

        $history = $this->service->history('SN-005');
        $this->assertCount(2, $history);
        $this->assertSame('transfer', $history[0]->reason);
        $this->assertSame('MAIN', $history[0]->fromLocCode);
        $this->assertSame('DEPOT', $history[0]->toLocCode);
        $this->assertSame(5, $history[0]->toShelfId);
        $this->assertSame(77, $history[0]->toBinId);
        // It was received on the default face, so "from" is that face, not null.
        $this->assertSame(4, $history[0]->fromAisleId);
        $this->assertSame(2, $history[0]->fromShelfId);
        $this->assertSame(3, $history[0]->fromBinId);
    }

    public function testMoveRetiredSerialIsRejected(): void
    {
        $this->service->register('SN-006', 'WIDGET', $this->face());
        $this->service->retire('SN-006');

        $this->expectException(InvalidSerialStateException::class);
        $this->service->move('SN-006', 'DEPOT');
    }

    public function testReserveAndUnreserveRoundTrip(): void
    {
        $this->service->register('SN-007', 'WIDGET', $this->face());

        $reserved = $this->service->reserve('SN-007');
        $this->assertSame(SerialNumberDto::STATUS_RESERVED, $reserved->status);

        $back = $this->service->unreserve('SN-007');
        $this->assertSame(SerialNumberDto::STATUS_AVAILABLE, $back->status);
    }

    public function testMarkSoldStartsWarrantyFromInstallDate(): void
    {
        $this->service->register('SN-008', 'WIDGET', $this->face());

        $sold = $this->service->markSold('SN-008', 'CUST-1', '2026-03-01', 730);

        $this->assertSame(SerialNumberDto::STATUS_INSTALLED, $sold->status);
        $owner = $this->service->currentOwner('SN-008');
        $this->assertNotNull($owner, 'selling must record an owner');
        $this->assertSame('CUST-1', $owner->ownerRef);
        $this->assertSame(OwnershipDto::KIND_DEBTOR, $owner->ownerKind);
        $this->assertSame('2026-03-01', $sold->installedDate);
        $this->assertSame('2028-02-29', $sold->warrantyEnd, '730 days from 2026-03-01');
        $this->assertTrue($sold->isWarrantyRunning());
    }

    public function testMarkSoldWithZeroDaysHasNoWarranty(): void
    {
        $this->service->register('SN-009', 'WIDGET');
        $sold = $this->service->markSold('SN-009', 'CUST-1', '2026-03-01', 0);

        $this->assertNull($sold->warrantyEnd);
    }

    public function testMarkSoldTwiceIsRejected(): void
    {
        $this->service->register('SN-010', 'WIDGET');
        $this->service->markSold('SN-010', 'CUST-1', '2026-03-01', 365);

        $this->expectException(InvalidSerialStateException::class);
        $this->service->markSold('SN-010', 'CUST-2', '2026-04-01', 365);
    }

    public function testMarkSoldLeavesAuditEntry(): void
    {
        $this->service->register('SN-011', 'WIDGET', $this->face());
        $this->service->markSold('SN-011', 'CUST-1', '2026-03-01', 365);

        $history = $this->service->history('SN-011');
        $this->assertSame('sold', $history[0]->reason);
    }

    public function testReturnClearsCustomerAndWarranty(): void
    {
        $this->service->register('SN-012', 'WIDGET', $this->face());
        $this->service->markSold('SN-012', 'CUST-1', '2026-03-01', 365);

        $returned = $this->service->returnSerial('SN-012');

        $this->assertSame(SerialNumberDto::STATUS_RETURNED, $returned->status);
        $this->assertNull(
            $this->service->currentOwner('SN-012'),
            'returning must close the ownership period'
        );
        $this->assertNull($returned->installedDate);
        $this->assertNull($returned->warrantyEnd, 'returning a unit stops the warranty clock');
    }

    public function testReturnOfUnsoldUnitIsRejected(): void
    {
        $this->service->register('SN-013', 'WIDGET');

        $this->expectException(InvalidSerialStateException::class);
        $this->service->returnSerial('SN-013');
    }

    public function testPutBackInStockReopensAvailability(): void
    {
        $this->service->register('SN-014', 'WIDGET', $this->face());
        $this->service->markSold('SN-014', 'CUST-1', '2026-03-01', 365);
        $this->service->returnSerial('SN-014');

        $back = $this->service->putBackInStock('SN-014', 'MAIN');

        $this->assertSame(SerialNumberDto::STATUS_AVAILABLE, $back->status);
        $this->assertSame('MAIN', $back->locCode);
    }

    public function testRetireIsTerminalAndIdempotent(): void
    {
        $this->service->register('SN-015', 'WIDGET', $this->face());

        $retired = $this->service->retire('SN-015');
        $this->assertSame(SerialNumberDto::STATUS_RETIRED, $retired->status);
        $this->assertNull($retired->locCode, 'a retired unit is not at any location');

        $again = $this->service->retire('SN-015');
        $this->assertSame(SerialNumberDto::STATUS_RETIRED, $again->status, 'retiring twice is a no-op');
    }

    public function testRetiredUnitCannotBeSold(): void
    {
        $this->service->register('SN-016', 'WIDGET');
        $this->service->retire('SN-016');

        $this->expectException(InvalidSerialStateException::class);
        $this->service->markSold('SN-016', 'CUST-1', '2026-03-01', 365);
    }

    public function testInstalledUnitCannotBePutBackDirectlyInStock(): void
    {
        $this->service->register('SN-017', 'WIDGET');
        $this->service->markSold('SN-017', 'CUST-1', '2026-03-01', 365);

        // installed -> available is NOT in the transition table; it must go
        // through returnSerial() first.
        $this->expectException(InvalidSerialStateException::class);
        $this->service->putBackInStock('SN-017');
    }

    public function testListByItemFiltersByStatus(): void
    {
        $this->service->register('SN-018', 'WIDGET');
        $this->service->register('SN-019', 'GADGET');

        $this->assertCount(1, $this->service->listByItem('WIDGET'));
        $this->assertCount(1, $this->service->listByItem('WIDGET', SerialNumberDto::STATUS_AVAILABLE));
        $this->assertCount(0, $this->service->listByItem('WIDGET', SerialNumberDto::STATUS_INSTALLED));
    }

    public function testCountAtLocationIgnoresRetired(): void
    {
        $this->service->register('SN-020', 'WIDGET', $this->face());
        $this->service->register('SN-021', 'WIDGET', $this->face());
        $this->service->register('SN-022', 'WIDGET', $this->face());
        $this->service->register('SN-023', 'GADGET', $this->face());
        $this->service->retire('SN-022');

        $this->assertSame(2, $this->service->countAtLocation('WIDGET', 'MAIN'));
    }

    public function testCountAtLocationCountsSoldUnitsStillResident(): void
    {
        // An installed unit keeps its last known loc_code, so it still counts as
        // physically present until it is moved or returned.
        $this->service->register('SN-024', 'WIDGET', $this->face());
        $this->service->markSold('SN-024', 'CUST-1', '2026-03-01', 365);

        $this->assertSame(1, $this->service->countAtLocation('WIDGET', 'MAIN'));
    }

    public function testHistoryIsNewestFirst(): void
    {
        $this->service->register('SN-025', 'WIDGET', $this->face());
        $this->service->move('SN-025', 'DEPOT', 4, 2, 3, 'transfer');
        $this->service->move('SN-025', 'MAIN', 4, 2, 3, 'transfer-back');

        $history = $this->service->history('SN-025');

        $this->assertCount(3, $history);
        $this->assertSame('transfer-back', $history[0]->reason);
        $this->assertSame('receipt', $history[2]->reason);
    }
}