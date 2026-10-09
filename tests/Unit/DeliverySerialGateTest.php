<?php
/**
 * @BABOK Related: FR-SN-006-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Service\DeliverySerialCapture;
use ksfraser\FrontAccounting\SerialNumber\Service\DeliverySerialGate;
use ksfraser\FrontAccounting\SerialNumber\Service\ScanResolver;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemControlRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemLookup;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * The gate that refuses to let a delivery through while a serial is missing.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.1.0
 */
class DeliverySerialGateTest extends TestCase
{
    /** @var InMemorySerialRepository */
    private $repo;

    /** @var InMemoryItemControlRepository */
    private $control;

    /** @var DeliverySerialCapture */
    private $capture;

    /** @var DeliverySerialGate */
    private $gate;

    protected function setUp(): void
    {
        $this->repo = new InMemorySerialRepository();
        $this->control = new InMemoryItemControlRepository();

        $items = new InMemoryItemLookup();
        $items->add('WIDGET', 'A widget');
        $items->add('MACHINE', 'A machine');

        $this->capture = new DeliverySerialCapture(
            $this->repo,
            $this->control,
            new ScanResolver($this->repo, $this->control, $items)
        );
        $this->gate = new DeliverySerialGate($this->capture);
    }

    /**
     * A minimal stand-in for an FA cart line.
     *
     * @param string $stockId
     * @param float  $qty
     * @return object
     */
    private function line(string $stockId, float $qty): object
    {
        $line = new \stdClass();
        $line->stock_id = $stockId;
        $line->qty = $qty;
        $line->description = 'x';

        return $line;
    }

    public function testAnOrderWithOnlyOrdinaryItemsIsNotBlocked(): void
    {
        $items = array($this->line('WIDGET', 5.0));

        $this->assertFalse($this->gate->shouldBlock($this->gate->requirementsFromCart($items)));
    }

    public function testAnOrderWithAShortSerialLineIsBlocked(): void
    {
        $this->control->control('MACHINE', 730);
        $items = array($this->line('WIDGET', 2.0), $this->line('MACHINE', 1.0));

        $this->assertTrue($this->gate->shouldBlock($this->gate->requirementsFromCart($items)));
    }

    public function testTheOrderIsReleasedOnceSerialsAreCaptured(): void
    {
        $this->control->control('MACHINE', 730);
        $this->repo->seedWithStatus('SER-A', 'MACHINE');

        $items = array($this->line('MACHINE', 1.0));
        $r = $this->gate->requirementsFromCart($items);

        $this->assertTrue($this->gate->shouldBlock($r));

        $this->capture->assign($r, 'MACHINE', 'SER-A');

        $this->assertFalse($this->gate->shouldBlock($r), 'capturing the serial must release the gate');
    }

    public function testRedirectPointsAtTheCapturePage(): void
    {
        $this->control->control('MACHINE', 730);
        $items = array($this->line('MACHINE', 1.0));
        $r = $this->gate->requirementsFromCart($items);

        $this->assertSame(
            '/ksf_fa/modules/ksf_FA_SerialNumber/serial_capture.php?order_no=77',
            $this->gate->redirectFor($r, 77, '/ksf_fa', 'modules/ksf_FA_SerialNumber/serial_capture.php')
        );
    }

    public function testThereIsNoRedirectWhenNothingBlocks(): void
    {
        $items = array($this->line('WIDGET', 1.0));
        $r = $this->gate->requirementsFromCart($items);

        $this->assertNull($this->gate->redirectFor($r, 1, '/ksf_fa', 'x.php'));
    }

    public function testTheMessageNamesWhatIsMissing(): void
    {
        $this->control->control('MACHINE', 730);
        $r = $this->gate->requirementsFromCart(array($this->line('MACHINE', 3.0)));

        $this->assertStringContainsString('3 serial(s) still needed', $this->gate->messageFor($r));
    }

    public function testLinesWithoutAStockIdAreSkipped(): void
    {
        $calloff = new \stdClass();
        $calloff->qty = 1.0;
        $calloff->description = 'a service line';

        $items = array($calloff, $this->line('WIDGET', 1.0));
        $r = $this->gate->requirementsFromCart($items);

        $this->assertCount(1, $r);
        $this->assertFalse($this->gate->shouldBlock($r));
    }

    public function testAnInvoiceLineHoldingAProductObjectIsUnderstood(): void
    {
        $this->control->control('MACHINE', 730);

        $product = new \stdClass();
        $product->stock_id = 'MACHINE';

        $line = new \stdClass();
        $line->product = $product;
        $line->qty = 1.0;

        $r = $this->gate->requirementsFromCart(array($line));

        $this->assertArrayHasKey('MACHINE', $r);
        $this->assertTrue($this->gate->shouldBlock($r));
    }

    public function testArrayShapedLinesAreAlsoUnderstood(): void
    {
        $this->control->control('MACHINE', 730);

        $r = $this->gate->requirementsFromCart(array(
            array('stock_id' => 'MACHINE', 'qty' => 2.0),
        ));

        $this->assertSame(2, $r['MACHINE']->shortfall());
    }

    public function testAnEmptyCartIsNotBlocked(): void
    {
        $this->assertFalse($this->gate->shouldBlock($this->gate->requirementsFromCart(array())));
    }

    public function testRepeatedCartLinesOfOneItemAggregate(): void
    {
        $this->control->control('MACHINE', 730);

        $r = $this->gate->requirementsFromCart(array(
            $this->line('MACHINE', 1.0),
            $this->line('MACHINE', 2.0),
        ));

        $this->assertCount(1, $r);
        $this->assertSame(3, $r['MACHINE']->shortfall());
    }

    public function testZeroQuantityNeverBlocks(): void
    {
        $this->control->control('MACHINE', 730);

        $r = $this->gate->requirementsFromCart(array($this->line('MACHINE', 0.0)));

        $this->assertFalse($this->gate->shouldBlock($r));
    }
}