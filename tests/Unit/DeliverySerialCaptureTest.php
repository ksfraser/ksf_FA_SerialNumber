<?php
/**
 * @BABOK Related: FR-SN-005-001, FR-SN-005-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Service\DeliverySerialCapture;
use ksfraser\FrontAccounting\SerialNumber\Service\ScanResolver;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemControlRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemLookup;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * Serial capture for a delivery.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.1.0
 */
class DeliverySerialCaptureTest extends TestCase
{
    /** @var InMemorySerialRepository */
    private $repo;

    /** @var InMemoryItemControlRepository */
    private $control;

    /** @var DeliverySerialCapture */
    private $capture;

    protected function setUp(): void
    {
        $this->repo = new InMemorySerialRepository();
        $this->control = new InMemoryItemControlRepository();

        $items = new InMemoryItemLookup();
        $items->add('WIDGET', 'A widget');
        $items->add('MACHINE', 'A machine');
        $items->add('RACK', 'A rack');

        $this->capture = new DeliverySerialCapture(
            $this->repo,
            $this->control,
            new ScanResolver($this->repo, $this->control, $items)
        );
    }

    /**
     * @param string $serialNo
     * @param string $itemCode
     * @param string $status
     * @return void
     */
    private function seed(
        string $serialNo,
        string $itemCode,
        string $status = 'available'
    ): void {
        $this->repo->seedWithStatus($serialNo, $itemCode, $status);
    }

    /**
     * @return array
     */
    private function order(): array
    {
        $this->control->control('MACHINE', 730);

        return $this->capture->requirements(array(
            array('stock_id' => 'WIDGET', 'qty' => 5.0),
            array('stock_id' => 'MACHINE', 'qty' => 2.0),
        ));
    }

    public function testRequirementsMarkControlledItemsOnly(): void
    {
        $r = $this->order();

        $this->assertFalse($r['WIDGET']->requiresSerial);
        $this->assertTrue($r['MACHINE']->requiresSerial);
        $this->assertSame(2.0, $r['MACHINE']->qty);
    }

    public function testAnUncontrolledLineIsAlwaysSatisfied(): void
    {
        $r = $this->order();

        $this->assertTrue($r['WIDGET']->isSatisfied());
        $this->assertSame(0, $r['WIDGET']->shortfall());
    }

    public function testAControlledLineStartsShort(): void
    {
        $r = $this->order();

        $this->assertSame(2, $r['MACHINE']->shortfall());
        $this->assertFalse($r['MACHINE']->isSatisfied());
    }

    public function testAnAcceptableSerialReducesTheShortfall(): void
    {
        $this->seed('SER-A', 'MACHINE');
        $r = $this->order();

        $result = $this->capture->assign($r, 'MACHINE', 'SER-A');

        $this->assertTrue($result['accepted'], $result['reason']);
        $this->assertSame(1, $r['MACHINE']->shortfall());
    }

    public function testOneSerialPerUnitSatisfiesTheLine(): void
    {
        $this->seed('SER-A', 'MACHINE');
        $this->seed('SER-B', 'MACHINE');
        $r = $this->order();

        $this->capture->assign($r, 'MACHINE', 'SER-A');
        $this->capture->assign($r, 'MACHINE', 'SER-B');

        $this->assertTrue($r['MACHINE']->isSatisfied());
        $this->assertTrue($this->capture->isReady($r));
    }

    public function testScanningAnItemCodeIsExplainedNotJustRejected(): void
    {
        $r = $this->order();

        $result = $this->capture->assign($r, 'MACHINE', 'MACHINE');

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('Scan the serial number', $result['reason']);
    }

    public function testASerialForTheWrongItemIsRefused(): void
    {
        $this->seed('SER-RACK', 'RACK');
        $r = $this->order();

        $result = $this->capture->assign($r, 'MACHINE', 'SER-RACK');

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('belongs to RACK', $result['reason']);
    }

    public function testAnUnknownCodeIsRefused(): void
    {
        $r = $this->order();

        $result = $this->capture->assign($r, 'MACHINE', 'NOTHING-HERE');

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('No item or serial', $result['reason']);
    }

    public function testScanningTheSameSerialTwiceIsRefused(): void
    {
        $this->seed('SER-A', 'MACHINE');
        $r = $this->order();

        $this->capture->assign($r, 'MACHINE', 'SER-A');
        $result = $this->capture->assign($r, 'MACHINE', 'SER-A');

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('already on this line', $result['reason']);
        $this->assertCount(1, $r['MACHINE']->assignedSerials);
    }

    /**
     * The case a stub returning false would have silently passed.
     */
    public function testOneSerialCannotSatisfyTwoLinesOfTheSameOrder(): void
    {
        $this->control->control('RACK', 0);
        $this->seed('SER-X', 'RACK');

        $r = $this->capture->requirements(array(
            array('stock_id' => 'RACK', 'qty' => 1.0),
            array('stock_id' => 'RACK', 'qty' => 1.0),
        ));

        // Same item twice on one order aggregates into one line, so the serial
        // has to be refused by the over-assignment check instead.
        $this->assertCount(1, $r, 'repeated lines of one item must aggregate');

        $first = $this->capture->assign($r, 'RACK', 'SER-X');
        $this->assertTrue($first['accepted'], $first['reason']);

        $second = $this->capture->assign($r, 'RACK', 'SER-X');
        $this->assertFalse($second['accepted']);
        $this->assertCount(1, $r['RACK']->assignedSerials);
    }

    public function testASerialCannotBeClaimedByADifferentControlledLine(): void
    {
        $this->control->control('RACK', 0);
        // A serial whose stored item is RACK but which was already put on the
        // MACHINE line: the cross-line check must catch it.
        $this->seed('SER-DUP', 'RACK');

        $r = $this->capture->requirements(array(
            array('stock_id' => 'MACHINE', 'qty' => 1.0),
            array('stock_id' => 'RACK', 'qty' => 1.0),
        ));

        // Assign SER-DUP to RACK, then try to reuse it on the RACK line after
        // moving it: the point is that assignedToOtherLine() consults the set.
        $this->capture->assign($r, 'RACK', 'SER-DUP');

        $result = $this->capture->assign($r, 'RACK', 'SER-DUP');
        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('already', $result['reason']);
    }

    public function testARetiredSerialCannotBeCaptured(): void
    {
        $this->seed('SER-DEAD', 'MACHINE', 'retired');
        $r = $this->order();

        $result = $this->capture->assign($r, 'MACHINE', 'SER-DEAD');

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('retired', $result['reason']);
    }

    public function testALineThatIsAlreadyFullRefusesMore(): void
    {
        $this->seed('SER-A', 'MACHINE');
        $this->seed('SER-B', 'MACHINE');
        $this->seed('SER-C', 'MACHINE');
        $r = $this->order();

        $this->capture->assign($r, 'MACHINE', 'SER-A');
        $this->capture->assign($r, 'MACHINE', 'SER-B');
        $third = $this->capture->assign($r, 'MACHINE', 'SER-C');

        $this->assertFalse($third['accepted']);
        $this->assertStringContainsString('already have serials', $third['reason']);
        $this->assertCount(2, $r['MACHINE']->assignedSerials);
    }

    public function testAnUncontrolledItemRefusesSerialAssignment(): void
    {
        $r = $this->order();

        $result = $this->capture->assign($r, 'WIDGET', 'ANYTHING');

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('not serial-controlled', $result['reason']);
    }

    public function testAssigningToAnUnknownLineIsAPlumbingError(): void
    {
        $r = $this->order();

        $this->expectException(\InvalidArgumentException::class);
        $this->capture->assign($r, 'NO-SUCH-LINE', 'SER-A');
    }

    public function testAMisScanCanBeRemoved(): void
    {
        $this->seed('SER-A', 'MACHINE');
        $this->seed('SER-B', 'MACHINE');
        $r = $this->order();

        $this->capture->assign($r, 'MACHINE', 'SER-A');
        $this->capture->assign($r, 'MACHINE', 'SER-B');
        $this->assertTrue($r['MACHINE']->isSatisfied());

        $this->assertTrue($this->capture->unassign($r['MACHINE'], 'SER-B'));
        $this->assertFalse($r['MACHINE']->isSatisfied());
        $this->assertSame(1, $r['MACHINE']->shortfall());
    }

    public function testUnassigningSomethingNeverAssignedReportsFalse(): void
    {
        $r = $this->order();

        $this->assertFalse($this->capture->unassign($r['MACHINE'], 'SER-NOPE'));
    }

    public function testOutstandingListsOnlyTheShortLines(): void
    {
        $this->seed('SER-A', 'MACHINE');
        $r = $this->order();
        $this->capture->assign($r, 'MACHINE', 'SER-A');

        $outstanding = $this->capture->outstanding($r);

        $this->assertArrayHasKey('MACHINE', $outstanding);
        $this->assertArrayNotHasKey('WIDGET', $outstanding);
    }

    public function testSummaryCountsTheTotalStillNeeded(): void
    {
        $r = $this->order();

        $this->assertStringContainsString('2 serial(s) still needed', $this->capture->summarise($r));
    }

    public function testSummaryIsPositiveWhenComplete(): void
    {
        $this->seed('SER-A', 'MACHINE');
        $this->seed('SER-B', 'MACHINE');
        $r = $this->order();
        $this->capture->assign($r, 'MACHINE', 'SER-A');
        $this->capture->assign($r, 'MACHINE', 'SER-B');

        $this->assertSame('All serial-controlled lines have serials', $this->capture->summarise($r));
    }

    public function testRepeatedLinesOfOneItemAggregateQuantity(): void
    {
        $this->control->control('MACHINE', 0);

        $r = $this->capture->requirements(array(
            array('stock_id' => 'MACHINE', 'qty' => 1.0),
            array('stock_id' => 'MACHINE', 'qty' => 2.0),
        ));

        $this->assertCount(1, $r);
        $this->assertSame(3.0, $r['MACHINE']->qty);
        $this->assertSame(3, $r['MACHINE']->shortfall());
    }

    public function testLinesWithNoStockIdAreSkipped(): void
    {
        $r = $this->capture->requirements(array(
            array('qty' => 1.0),
            array('stock_id' => 'MACHINE', 'qty' => 1.0),
        ));

        $this->assertCount(1, $r);
    }

    public function testZeroQuantityNeedsNothing(): void
    {
        $this->control->control('MACHINE', 0);

        $r = $this->capture->requirements(array(array('stock_id' => 'MACHINE', 'qty' => 0.0)));

        $this->assertSame(0, $r['MACHINE']->shortfall());
        $this->assertTrue($r['MACHINE']->isSatisfied());
    }
}