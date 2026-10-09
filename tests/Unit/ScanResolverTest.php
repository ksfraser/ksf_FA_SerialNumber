<?php
/**
 * @BABOK Related: FR-SN-004-002, BR-SN-001-005
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Dto\ScanResolution;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;
use ksfraser\FrontAccounting\SerialNumber\Service\ScanResolver;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemControlRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemLookup;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * A scanner produces a string. These tests pin down which of the three useful
 * answers that string gets.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.1.0
 */
class ScanResolverTest extends TestCase
{
    /** @var InMemorySerialRepository */
    private $repo;

    /** @var InMemoryItemControlRepository */
    private $control;

    /** @var InMemoryItemLookup */
    private $items;

    /** @var ScanResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->repo = new InMemorySerialRepository();
        $this->control = new InMemoryItemControlRepository();
        $this->items = new InMemoryItemLookup();
        $this->resolver = new ScanResolver($this->repo, $this->control, $this->items);

        $this->items->add('WIDGET', 'A widget');
        $this->items->add('MACHINE', 'A machine');
    }

    /**
     * @param string $serialNo
     * @param string $itemCode
     * @param string $status
     * @return void
     */
    private function seedSerial(
        string $serialNo,
        string $itemCode = 'MACHINE',
        string $status = SerialNumberDto::STATUS_AVAILABLE
    ): void {
        $serial = new SerialNumberDto($serialNo, $itemCode);
        $serial->status = $status;
        $serial->locCode = 'MAIN';
        $serial->aisleId = 4;
        $serial->shelfId = 2;
        $serial->binId = 3;
        $this->repo->seed($serial);
    }

    public function testAnOrdinaryItemResolvesToItem(): void
    {
        $r = $this->resolver->resolve('WIDGET');

        $this->assertSame(ScanResolution::KIND_ITEM, $r->kind);
        $this->assertSame('WIDGET', $r->itemCode);
        $this->assertSame('A widget', $r->itemDescription);
        $this->assertTrue($r->isPickable());
        $this->assertFalse($r->needsSerial());
    }

    /**
     * The case that must never be confused with the one above.
     */
    public function testASerialControlledItemResolvesToItemRequiresSerial(): void
    {
        $this->control->control('MACHINE', 730);

        $r = $this->resolver->resolve('MACHINE');

        $this->assertSame(ScanResolution::KIND_ITEM_REQUIRES_SERIAL, $r->kind);
        $this->assertTrue($r->needsSerial());
        $this->assertFalse(
            $r->isPickable(),
            'a serial-controlled item with no serial scanned must NOT be pickable'
        );
        $this->assertSame('Scan the serial number for MACHINE', $r->describe());
    }

    public function testAResolvedSerialCarriesTheSkuAndTheBin(): void
    {
        $this->seedSerial('SER-1');

        $r = $this->resolver->resolve('SER-1');

        $this->assertSame(ScanResolution::KIND_SERIAL, $r->kind);
        $this->assertSame('SER-1', $r->serialNo);
        $this->assertSame('MACHINE', $r->itemCode);
        $this->assertSame('A machine', $r->itemDescription);
        $this->assertSame(
            array('loc_code' => 'MAIN', 'aisle_id' => 4, 'shelf_id' => 2, 'bin_id' => 3),
            $r->pickFace()
        );
        $this->assertTrue($r->isPickable());
    }

    public function testAnUnknownCodeIsUnknownNotNeedsSerial(): void
    {
        // We do not know what it is, so we must not claim it needs a serial.
        $r = $this->resolver->resolve('NO-SUCH-THING');

        $this->assertSame(ScanResolution::KIND_UNKNOWN, $r->kind);
        $this->assertFalse($r->needsSerial());
        $this->assertFalse($r->isPickable());
        $this->assertStringContainsString('No item or serial', $r->reason);
    }

    public function testAnEmptyScanIsUnknown(): void
    {
        $r = $this->resolver->resolve('   ');

        $this->assertSame(ScanResolution::KIND_UNKNOWN, $r->kind);
        $this->assertSame('Nothing was scanned', $r->reason);
    }

    public function testScansAreTrimmed(): void
    {
        $this->seedSerial('SER-2');

        $r = $this->resolver->resolve('  SER-2  ');

        $this->assertSame(ScanResolution::KIND_SERIAL, $r->kind);
        $this->assertSame('SER-2', $r->serialNo);
    }

    /**
     * A serial is the more specific fact, so it must win a collision.
     */
    public function testASerialWinsOverACollidingItemCode(): void
    {
        $this->items->add('SER-3', 'An item that looks like a serial');
        $this->seedSerial('SER-3');

        $r = $this->resolver->resolve('SER-3');

        $this->assertSame(ScanResolution::KIND_SERIAL, $r->kind);
    }

    public function testARetiredSerialIsNotPickable(): void
    {
        $this->seedSerial('SER-4', 'MACHINE', SerialNumberDto::STATUS_RETIRED);

        $r = $this->resolver->resolve('SER-4');

        $this->assertSame(ScanResolution::KIND_SERIAL, $r->kind);
        $this->assertFalse(
            $r->isPickable(),
            'a retired unit is not stock, wherever it happens to sit'
        );
    }

    public function testAReservedSerialIsStillPickable(): void
    {
        $this->seedSerial('SER-5', 'MACHINE', SerialNumberDto::STATUS_RESERVED);

        $this->assertTrue($this->resolver->resolve('SER-5')->isPickable());
    }

    public function testAnInstalledSerialIsNotPickable(): void
    {
        $this->seedSerial('SER-6', 'MACHINE', SerialNumberDto::STATUS_INSTALLED);

        $this->assertFalse($this->resolver->resolve('SER-6')->isPickable());
    }

    public function testAnUnshelvedSerialSaysSo(): void
    {
        $serial = new SerialNumberDto('SER-7', 'MACHINE');
        $serial->status = SerialNumberDto::STATUS_AVAILABLE;
        $this->repo->seed($serial);

        $r = $this->resolver->resolve('SER-7');

        $this->assertSame(ScanResolution::KIND_SERIAL, $r->kind);
        $this->assertFalse($r->isShelved());
        $this->assertNull($r->pickFace());
        $this->assertSame('Serial SER-7 is not shelved', $r->describe());
    }

    public function testWarrantyDaysComeFromTheItemDefault(): void
    {
        $this->control->control('MACHINE', 730);
        $this->seedSerial('SER-8');

        $r = $this->resolver->resolve('SER-8');

        $this->assertSame(730, $r->warrantyDays);
    }

    public function testAnOrdinaryItemHasNoWarrantyByDefault(): void
    {
        $this->assertSame(0, $this->resolver->resolve('WIDGET')->warrantyDays);
    }

    public function testToArrayCarriesTheWholeResolution(): void
    {
        $this->seedSerial('SER-9');

        $row = $this->resolver->resolve('SER-9')->toArray();

        $this->assertSame(ScanResolution::KIND_SERIAL, $row['kind']);
        $this->assertSame('SER-9', $row['serial_no']);
        $this->assertSame('MACHINE', $row['item_code']);
        $this->assertSame(3, $row['bin_id']);
    }

    public function testEveryKindIsValid(): void
    {
        $this->assertTrue(ScanResolution::isValidKind(ScanResolution::KIND_ITEM));
        $this->assertTrue(ScanResolution::isValidKind(ScanResolution::KIND_ITEM_REQUIRES_SERIAL));
        $this->assertTrue(ScanResolution::isValidKind(ScanResolution::KIND_SERIAL));
        $this->assertTrue(ScanResolution::isValidKind(ScanResolution::KIND_UNKNOWN));
        $this->assertFalse(ScanResolution::isValidKind('something_else'));
    }

    public function testAnItemNeverNeedsASerialOnceReleased(): void
    {
        $this->control->control('MACHINE', 730);
        $this->assertTrue($this->resolver->resolve('MACHINE')->needsSerial());

        $this->control->release('MACHINE');

        $r = $this->resolver->resolve('MACHINE');
        $this->assertSame(ScanResolution::KIND_ITEM, $r->kind);
        $this->assertFalse($r->needsSerial());
    }
}