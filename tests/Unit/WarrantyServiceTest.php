<?php
/**
 * @BABOK Related: FR-SN-001-003
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;
use ksfraser\FrontAccounting\SerialNumber\Exception\SerialNotFoundException;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Service\WarrantyService;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * Warranty facts: cover, days remaining, extension and the renewal worklist.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.0.0
 */
class WarrantyServiceTest extends TestCase
{
    /** @var InMemorySerialRepository */
    private $repo;

    /** @var SerialNumberService */
    private $serials;

    /** @var WarrantyService */
    private $warranty;

    protected function setUp(): void
    {
        $this->repo = new InMemorySerialRepository();
        $this->serials = new SerialNumberService($this->repo, '2026-03-01 09:00:00');
        $this->warranty = new WarrantyService($this->repo, '2026-03-01');
    }

    public function testInstalledUnitWithFutureEndIsCovered(): void
    {
        $this->serials->register('W-1', 'WIDGET');
        $this->serials->markSold('W-1', 'CUST', '2026-01-01', 365);

        // Re-fetch: the repository clones on write, so a DTO held across a
        // mutating call is a stale snapshot.
        $serial = $this->serials->get('W-1');

        $this->assertTrue($this->warranty->isCovered($serial, '2026-03-01'));
    }

    public function testCoverIsInclusiveOfEndDate(): void
    {
        $this->serials->register('W-2', 'WIDGET');
        $this->serials->markSold('W-2', 'CUST', '2026-01-01', 31);
        $serial = $this->serials->get('W-2');

        // sold 2026-01-01 + 31 days = 2026-02-01
        $this->assertTrue($this->warranty->isCovered($serial, '2026-02-01'), 'last day is covered');
        $this->assertFalse($this->warranty->isCovered($serial, '2026-02-02'), 'day after is not');
    }

    public function testDaysRemainingCountsEndDay(): void
    {
        $this->serials->register('W-3', 'WIDGET');
        $this->serials->markSold('W-3', 'CUST', '2026-01-01', 31);
        $serial = $this->serials->get('W-3');

        $this->assertSame(1, $this->warranty->daysRemaining($serial, '2026-02-01'));
        $this->assertSame(0, $this->warranty->daysRemaining($serial, '2026-02-02'));
    }

    public function testAvailableUnitHasNoCoverEvenWithAnEndDate(): void
    {
        // Guards the case where a stale warranty_end survives on the row after a
        // return: status must win, or a scrapped unit keeps claiming cover.
        $serial = $this->serials->register('W-4', 'WIDGET');
        $serial->warrantyEnd = '2030-01-01';
        $this->repo->update($serial);

        $this->assertFalse($this->warranty->isCovered($serial, '2026-03-01'));
        $this->assertSame(0, $this->warranty->daysRemaining($serial, '2026-03-01'));
    }

    public function testReturnedUnitLosesCover(): void
    {
        $this->serials->register('W-5', 'WIDGET');
        $this->serials->markSold('W-5', 'CUST', '2026-01-01', 730);
        $this->serials->returnSerial('W-5');

        $serial = $this->serials->get('W-5');
        $this->assertFalse($this->warranty->isCovered($serial, '2026-03-01'));
    }

    public function testNoWarrantyRecordedMeansNoCover(): void
    {
        $this->serials->register('W-6', 'WIDGET');
        $this->serials->markSold('W-6', 'CUST', '2026-01-01', 0);

        $serial = $this->serials->get('W-6');
        $this->assertFalse($this->warranty->isCovered($serial, '2026-03-01'));
    }

    public function testExtendPushesEndDateOut(): void
    {
        $this->serials->register('W-7', 'WIDGET');
        $this->serials->markSold('W-7', 'CUST', '2026-01-01', 365);

        // Existing cover runs to 2027-01-01; 365 more days lands on 2028-01-01.
        // Extension adds to REMAINING cover, it does not reset the clock.
        $extended = $this->warranty->extend('W-7', 365, '2026-02-01');

        $this->assertSame('2028-01-01', $extended->warrantyEnd);
    }

    public function testExtendRestartsFromReferenceWhenAlreadyExpired(): void
    {
        // A repair performed long after expiry must not yield a warranty that is
        // already in the past.
        $this->serials->register('W-8', 'WIDGET');
        $this->serials->markSold('W-8', 'CUST', '2026-01-01', 30);

        $extended = $this->warranty->extend('W-8', 365, '2026-06-01');

        $this->assertSame('2027-06-01', $extended->warrantyEnd);
    }

    public function testExtendRejectsNonPositiveDays(): void
    {
        $this->serials->register('W-9', 'WIDGET');
        $this->serials->markSold('W-9', 'CUST', '2026-01-01', 365);

        $this->expectException(\InvalidArgumentException::class);
        $this->warranty->extend('W-9', 0);
    }

    public function testExtendUnknownSerialThrows(): void
    {
        $this->expectException(SerialNotFoundException::class);
        $this->warranty->extend('NOPE', 365);
    }

    public function testExpiringSoonReturnsOnlyCoverEndingInsideWindow(): void
    {
        // ends 2027-01-01 -- far outside a 30 day window from 2026-03-01
        $this->serials->register('W-10', 'WIDGET');
        $this->serials->markSold('W-10', 'CUST', '2026-01-01', 365);

        // ends 2026-03-10 -- inside
        $this->serials->register('W-11', 'WIDGET');
        $this->serials->markSold('W-11', 'CUST', '2026-01-01', 68);

        // available, not installed
        $this->serials->register('W-12', 'WIDGET');

        $expiring = $this->warranty->expiringSoon('WIDGET', 30, '2026-03-01');

        $this->assertCount(1, $expiring);
        $this->assertSame('W-11', $expiring[0]->serialNo);
    }

    public function testExpiringSoonIgnoresAlreadyExpired(): void
    {
        $this->serials->register('W-13', 'WIDGET');
        $this->serials->markSold('W-13', 'CUST', '2025-01-01', 30);

        $this->assertCount(0, $this->warranty->expiringSoon('WIDGET', 30, '2026-03-01'));
    }

    public function testDefaultDateIsFrozenToday(): void
    {
        $this->serials->register('W-14', 'WIDGET');
        $this->serials->markSold('W-14', 'CUST', '2026-03-01', 365);

        $serial = $this->serials->get('W-14');

        // No onDate given -> the frozen 2026-03-01 from the constructor.
        $this->assertTrue($this->warranty->isCovered($serial));
        $this->assertSame(366, $this->warranty->daysRemaining($serial));
    }

    public function testToArrayOnBareDtoDoesNotFatal(): void
    {
        // Regression guard: a typed property without a default is uninitialised
        // and reading it throws. Every property here must be defaulted.
        $dto = new SerialNumberDto('BARE', 'ITEM');

        $array = $dto->toArray();

        $this->assertSame('BARE', $array['serial_no']);
        $this->assertNull($array['loc_code']);
        $this->assertNull($array['warranty_end']);
        $this->assertSame(SerialNumberDto::STATUS_AVAILABLE, $array['status']);
    }
}