<?php
/**
 * @BABOK Related: FR-SN-001-004
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Service\BatchNumberService;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryBatchRepository;

/**
 * Batch registration and FEFO allocation.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.0.0
 */
class BatchNumberServiceTest extends TestCase
{
    /** @var InMemoryBatchRepository */
    private $repo;

    /** @var BatchNumberService */
    private $service;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->service = new BatchNumberService($this->repo);
    }

    public function testRegisterStoresBatch(): void
    {
        $batch = $this->service->register('B-1', 'MILK', 24.0, array('expiryDate' => '2026-04-01'));

        $this->assertSame('B-1', $batch->batchNo);
        $this->assertSame(24.0, $batch->qty);
        $this->assertSame('2026-04-01', $batch->expiryDate);
        $this->assertGreaterThan(0, $batch->id);
    }

    public function testRegisterRejectsBadInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->register('', 'MILK', 1.0);
    }

    public function testRegisterRejectsZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->register('B-2', 'MILK', 0.0);
    }

    public function testRegisterAllowsFractionalQuantity(): void
    {
        // Weighed goods must not be truncated to an int.
        $batch = $this->service->register('B-3', 'APPLES', 1.75);
        $this->assertSame(1.75, $batch->qty);
    }

    public function testFefoConsumesEarliestExpiryFirst(): void
    {
        $this->service->register('LATE', 'MILK', 10.0, array('expiryDate' => '2026-06-01'));
        $this->service->register('SOON', 'MILK', 10.0, array('expiryDate' => '2026-04-01'));

        $allocations = $this->service->allocateFefo('MILK', 4.0, '2026-03-01');

        $this->assertCount(1, $allocations);
        $this->assertSame('SOON', $allocations[0]->batchNo, 'earliest expiry goes first');
        $this->assertSame(4.0, $allocations[0]->qty);
        $this->assertSame(6.0, $this->repo->find('SOON', 'MILK')->qty);
    }

    public function testFefoSpansMultipleBatches(): void
    {
        $this->service->register('B1', 'MILK', 5.0, array('expiryDate' => '2026-04-01'));
        $this->service->register('B2', 'MILK', 5.0, array('expiryDate' => '2026-05-01'));
        $this->service->register('B3', 'MILK', 5.0, array('expiryDate' => '2026-06-01'));

        $allocations = $this->service->allocateFefo('MILK', 12.0, '2026-03-01');

        $this->assertCount(3, $allocations);
        $this->assertSame(array('B1', 'B2', 'B3'), array(
            $allocations[0]->batchNo,
            $allocations[1]->batchNo,
            $allocations[2]->batchNo,
        ));
        $this->assertSame(5.0, $allocations[0]->qty);
        $this->assertSame(5.0, $allocations[1]->qty);
        $this->assertSame(2.0, $allocations[2]->qty);
        $this->assertSame(3.0, $this->repo->find('B3', 'MILK')->qty);
    }

    public function testFefoUsesUndatedBatchLast(): void
    {
        // A batch with no expiry never expires, so it must be the fallback rather
        // than the first thing issued (MySQL sorts NULL first, hence the CASE).
        $this->service->register('NODATE', 'MILK', 10.0);
        $this->service->register('DATED', 'MILK', 10.0, array('expiryDate' => '2026-06-01'));

        $allocations = $this->service->allocateFefo('MILK', 12.0, '2026-03-01');

        $this->assertSame('DATED', $allocations[0]->batchNo);
        $this->assertSame('NODATE', $allocations[1]->batchNo);
    }

    public function testFefoSkipsExpiredBatches(): void
    {
        $this->service->register('DEAD', 'MILK', 10.0, array('expiryDate' => '2026-02-01'));
        $this->service->register('LIVE', 'MILK', 10.0, array('expiryDate' => '2026-06-01'));

        $allocations = $this->service->allocateFefo('MILK', 3.0, '2026-03-01');

        $this->assertCount(1, $allocations);
        $this->assertSame('LIVE', $allocations[0]->batchNo);
        $this->assertSame(10.0, $this->repo->find('DEAD', 'MILK')->qty, 'expired stock is untouched');
    }

    public function testExpiryIsInclusiveOfTheExpiryDateItself(): void
    {
        $this->service->register('TODAY', 'MILK', 10.0, array('expiryDate' => '2026-03-01'));
        $this->service->register('TOMORROW', 'MILK', 10.0, array('expiryDate' => '2026-03-02'));

        $onExpiry = $this->service->allocateFefo('MILK', 1.0, '2026-03-01');
        $this->assertSame('TODAY', $onExpiry[0]->batchNo, 'good on the expiry date itself');

        $dayAfter = $this->service->allocateFefo('MILK', 1.0, '2026-03-02');
        $this->assertSame('TOMORROW', $dayAfter[0]->batchNo, 'dead the following day');
    }

    public function testFefoReportsShortfallWhenStockRunsOut(): void
    {
        $this->service->register('ONLY', 'MILK', 3.0, array('expiryDate' => '2026-06-01'));

        $allocations = $this->service->allocateFefo('MILK', 10.0, '2026-03-01');

        $this->assertSame(7.0, $this->service->shortfall($allocations, 10.0));
    }

    public function testShortfallIsZeroWhenFullyAllocated(): void
    {
        $this->service->register('FULL', 'MILK', 10.0, array('expiryDate' => '2026-06-01'));

        $allocations = $this->service->allocateFefo('MILK', 4.0, '2026-03-01');

        $this->assertSame(0.0, $this->service->shortfall($allocations, 4.0));
    }

    public function testAllocateZeroOrNegativeReturnsNothing(): void
    {
        $this->service->register('B', 'MILK', 10.0, array('expiryDate' => '2026-06-01'));

        $this->assertSame(array(), $this->service->allocateFefo('MILK', 0.0, '2026-03-01'));
        $this->assertSame(array(), $this->service->allocateFefo('MILK', -5.0, '2026-03-01'));
    }

    public function testFefoIsScopedToOneItem(): void
    {
        $this->service->register('OTHER', 'BREAD', 10.0, array('expiryDate' => '2026-01-01'));
        $this->service->register('MINE', 'MILK', 10.0, array('expiryDate' => '2026-06-01'));

        $allocations = $this->service->allocateFefo('MILK', 1.0, '2026-03-01');

        $this->assertCount(1, $allocations);
        $this->assertSame('MINE', $allocations[0]->batchNo);
    }

    public function testWriteOffExpiredMarksAndTotals(): void
    {
        $this->service->register('OLD1', 'MILK', 5.0, array('expiryDate' => '2026-01-01'));
        $this->service->register('OLD2', 'MILK', 2.5, array('expiryDate' => '2026-02-01'));
        $this->service->register('LIVE', 'MILK', 10.0, array('expiryDate' => '2026-12-01'));

        $writtenOff = $this->service->writeOffExpired('MILK', '2026-03-01');

        $this->assertSame(7.5, $writtenOff);
        $this->assertSame('expired', $this->repo->find('OLD1', 'MILK')->status);
        $this->assertSame('active', $this->repo->find('LIVE', 'MILK')->status);
    }

    public function testExpiredBatchIsNotAllocatedAfterWriteOff(): void
    {
        $this->service->register('OLD', 'MILK', 5.0, array('expiryDate' => '2026-01-01'));
        $this->service->writeOffExpired('MILK', '2026-03-01');

        $this->assertSame(array(), $this->service->allocateFefo('MILK', 1.0, '2026-03-01'));
    }

    public function testNeverExpiresWhenNoExpiryRecorded(): void
    {
        $batch = $this->service->register('NODATE', 'MILK', 5.0);

        $this->assertFalse($batch->isExpiredOn('2099-01-01'));
    }
}