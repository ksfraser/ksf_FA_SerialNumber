<?php
/**
 * @BABOK Related: FR-SN-005-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Dto\OwnershipDto;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;
use ksfraser\FrontAccounting\SerialNumber\Service\DeliverySerialCapture;
use ksfraser\FrontAccounting\SerialNumber\Service\DeliverySerialCommit;
use ksfraser\FrontAccounting\SerialNumber\Service\ScanResolver;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemControlRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryItemLookup;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryOwnershipRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * Binding captured serials to a delivery that now has a document number.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.1.0
 */
class DeliverySerialCommitTest extends TestCase
{
    /** @var InMemorySerialRepository */
    private $repo;

    /** @var InMemoryOwnershipRepository */
    private $owners;

    /** @var InMemoryItemControlRepository */
    private $control;

    /** @var DeliverySerialCapture */
    private $capture;

    /** @var SerialNumberService */
    private $serials;

    protected function setUp(): void
    {
        $this->repo = new InMemorySerialRepository();
        $this->owners = new InMemoryOwnershipRepository();
        $this->control = new InMemoryItemControlRepository();
        $this->serials = new SerialNumberService($this->repo, '2026-03-01 09:00:00', $this->owners);

        $items = new InMemoryItemLookup();
        $items->add('WIDGET', 'A widget');
        $items->add('MACHINE', 'A machine');

        $this->capture = new DeliverySerialCapture(
            $this->repo,
            $this->control,
            new ScanResolver($this->repo, $this->control, $items)
        );
    }

    /**
     * @return \ksfraser\FrontAccounting\SerialNumber\Dto\CartSerialRequirement[]
     */
    private function capturedOrder(): array
    {
        $this->control->control('MACHINE', 730);
        $this->repo->seedWithStatus('SER-A', 'MACHINE');
        $this->repo->seedWithStatus('SER-B', 'MACHINE');

        $r = $this->capture->requirements(array(
            array('stock_id' => 'WIDGET', 'qty' => 3.0),
            array('stock_id' => 'MACHINE', 'qty' => 2.0),
        ));

        $this->capture->assign($r, 'MACHINE', 'SER-A');
        $this->capture->assign($r, 'MACHINE', 'SER-B');

        return $r;
    }

    public function testCommitSellsEveryCapturedSerial(): void
    {
        $r = $this->capturedOrder();
        $commit = new DeliverySerialCommit($this->serials);

        $result = $commit->commit($r, 42, '2026-03-01', OwnershipDto::KIND_DEBTOR, 'CUST-1');

        $this->assertSame(2, $result['committed']);
        $this->assertSame(SerialNumberDto::STATUS_INSTALLED, $this->serials->get('SER-A')->status);
        $this->assertSame(SerialNumberDto::STATUS_INSTALLED, $this->serials->get('SER-B')->status);
    }

    public function testCommitLeavesUncontrolledItemsAlone(): void
    {
        $r = $this->capturedOrder();
        $commit = new DeliverySerialCommit($this->serials);

        // WIDGET needs no serial, so there is nothing to commit for it.
        $this->assertCount(0, $r['WIDGET']->assignedSerials);
        $this->assertSame(2, $commit->commit($r, 42, '2026-03-01', 'debtor', 'CUST-1')['committed']);
    }

    public function testCommitStartsTheWarrantyFromTheItemDefault(): void
    {
        $r = $this->capturedOrder();
        $commit = new DeliverySerialCommit($this->serials);

        $commit->commit($r, 42, '2026-03-01', OwnershipDto::KIND_DEBTOR, 'CUST-1');

        // 730 days from 2026-03-01.
        $this->assertSame('2028-02-29', $this->serials->get('SER-A')->warrantyEnd);
    }

    public function testCommitOpensAnOwnershipPeriod(): void
    {
        $r = $this->capturedOrder();
        $commit = new DeliverySerialCommit($this->serials);

        $commit->commit($r, 42, '2026-03-01', OwnershipDto::KIND_DEBTOR, 'CUST-1');

        $owner = $this->serials->currentOwner('SER-A');
        $this->assertNotNull($owner);
        $this->assertSame('CUST-1', $owner->ownerRef);
        $this->assertSame('2026-03-01', $owner->ownedFrom);
    }

    public function testACommittedSerialIsNoLongerPickable(): void
    {
        $r = $this->capturedOrder();
        $commit = new DeliverySerialCommit($this->serials);
        $commit->commit($r, 42, '2026-03-01', OwnershipDto::KIND_DEBTOR, 'CUST-1');

        // This is what stops a second delivery claiming the same machine.
        $again = $this->capture->assign($r, 'MACHINE', 'SER-A');
        $this->assertFalse($again['accepted']);
    }

    public function testNothingCommittedWhenNoSerialsWereCaptured(): void
    {
        $this->control->control('MACHINE', 0);
        $r = $this->capture->requirements(array(array('stock_id' => 'MACHINE', 'qty' => 1.0)));
        $commit = new DeliverySerialCommit($this->serials);

        $result = $commit->commit($r, 42, '2026-03-01', OwnershipDto::KIND_DEBTOR, 'CUST-1');

        $this->assertSame(0, $result['committed']);
        $this->assertSame(array(), $result['serials']);
    }

    public function testTheCapturedLineIsReadyBeforeCommit(): void
    {
        $r = $this->capturedOrder();

        $this->assertTrue($this->capture->isReady($r), 'capture gate must be satisfied first');
    }

    public function testAnUncapturedOrderIsNotReady(): void
    {
        $this->control->control('MACHINE', 0);
        $this->repo->seedWithStatus('SER-A', 'MACHINE');
        $r = $this->capture->requirements(array(array('stock_id' => 'MACHINE', 'qty' => 1.0)));

        $this->assertFalse($this->capture->isReady($r));
        $this->assertNotSame(
            array(),
            $this->capture->outstanding($r),
            'the gate must report the missing serial rather than pass'
        );
    }
}
