<?php
/**
 * @BABOK Related: FR-SN-002-001, BR-SN-001-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\SerialNumber\Dto\OwnershipDto;
use ksfraser\FrontAccounting\SerialNumber\Exception\InvalidSerialStateException;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemoryOwnershipRepository;
use ksfraser\FrontAccounting\SerialNumber\Tests\Fakes\InMemorySerialRepository;

/**
 * Ownership history: the case a single mutable owner column cannot represent.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.1.0
 */
class OwnershipTest extends TestCase
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
        $this->service = $this->serviceAt('2026-03-01 09:00:00');
    }

    /**
     * A service sharing this test's repositories, running on a given clock.
     *
     * returnSerial() dates the ownership period from the injected clock, while
     * markSold() takes an explicit date -- so a resale test needs a clock that
     * falls BETWEEN the two sale dates for the periods to come out coherent.
     *
     * @param string $clock 'Y-m-d H:i:s'
     * @return SerialNumberService
     */
    private function serviceAt(string $clock): SerialNumberService
    {
        return new SerialNumberService($this->repo, $clock, $this->owners);
    }

    /**
     * @return void
     */
    private function sold(string $serialNo, string $ownerRef, string $onDate): void
    {
        $this->service->register($serialNo, 'WIDGET');
        $this->service->markSold($serialNo, $ownerRef, $onDate, 365);
    }

    public function testAnUnsoldUnitHasNoOwner(): void
    {
        $this->service->register('SN-100', 'WIDGET');

        $this->assertNull($this->service->currentOwner('SN-100'));
        $this->assertSame(array(), $this->service->ownershipHistory('SN-100'));
    }

    public function testSellingOpensExactlyOnePeriod(): void
    {
        $this->sold('SN-101', 'CUST-1', '2026-03-01');

        $owner = $this->service->currentOwner('SN-101');

        $this->assertNotNull($owner);
        $this->assertSame('CUST-1', $owner->ownerRef);
        $this->assertSame('2026-03-01', $owner->ownedFrom);
        $this->assertNull($owner->ownedTo, 'a current owner has no end date');
        $this->assertTrue($owner->isCurrent());
        $this->assertCount(1, $this->owners->history('SN-101'));
    }

    /**
     * The whole point of the xref: a resale keeps the earlier holder.
     */
    public function testAResaleKeepsThePreviousOwnerInTheTrail(): void
    {
        $service = $this->serviceAt('2026-03-15 09:00:00');

        $service->register('SN-102', 'WIDGET');
        $service->markSold('SN-102', 'CUST-1', '2026-01-01', 365);

        // Comes back on the 15th, then is resold in September.
        $service->returnSerial('SN-102');
        $service->putBackInStock('SN-102');
        $service->markSold('SN-102', 'CUST-2', '2026-09-01', 365);

        $history = $service->ownershipHistory('SN-102');

        $this->assertCount(2, $history, 'both holders must remain on record');

        $first = $history[0];
        $second = $history[1];

        $this->assertSame('CUST-1', $first->ownerRef);
        $this->assertSame('2026-01-01', $first->ownedFrom);
        $this->assertSame(
            '2026-03-15',
            $first->ownedTo,
            'the first period is closed, not deleted'
        );

        $this->assertSame('CUST-2', $second->ownerRef);
        $this->assertSame('2026-09-01', $second->ownedFrom);
        $this->assertNull($second->ownedTo);
    }

    public function testAUnitNeverHasTwoOpenOwners(): void
    {
        $this->sold('SN-103', 'CUST-1', '2026-03-01');

        $open = 0;

        foreach ($this->service->ownershipHistory('SN-103') as $row) {
            if ($row->isCurrent()) {
                $open++;
            }
        }

        $this->assertSame(1, $open, 'exactly one open owner, ever');
    }

    public function testHistoryIsOldestFirst(): void
    {
        $service = $this->serviceAt('2026-03-15 09:00:00');

        $service->register('SN-104', 'WIDGET');
        $service->markSold('SN-104', 'CUST-1', '2026-01-01', 365);
        $service->returnSerial('SN-104');
        $service->putBackInStock('SN-104');
        $service->markSold('SN-104', 'CUST-2', '2026-06-01', 365);

        $history = $service->ownershipHistory('SN-104');

        $this->assertSame('CUST-1', $history[0]->ownerRef);
        $this->assertSame('CUST-2', $history[1]->ownerRef);
    }

    public function testOwnerAsAtResolvesAPastOwner(): void
    {
        $service = $this->serviceAt('2026-03-15 09:00:00');

        $service->register('SN-105', 'WIDGET');
        $service->markSold('SN-105', 'CUST-1', '2026-01-01', 365);
        $service->returnSerial('SN-105');
        $service->putBackInStock('SN-105');
        $service->markSold('SN-105', 'CUST-2', '2026-06-01', 365);

        // This is the question warranty entitlement actually turns on.
        $this->assertSame('CUST-1', $service->ownerAsAt('SN-105', '2026-03-10')->ownerRef);
        $this->assertSame('CUST-2', $service->ownerAsAt('SN-105', '2026-07-01')->ownerRef);
    }

    public function testOwnerAsAtIncludesBothEndsOfAPeriod(): void
    {
        $service = $this->serviceAt('2026-03-15 09:00:00');

        $service->register('SN-106', 'WIDGET');
        $service->markSold('SN-106', 'CUST-1', '2026-01-01', 365);
        $service->returnSerial('SN-106');   // closes the period on 2026-03-15
        $service->putBackInStock('SN-106');
        $service->markSold('SN-106', 'CUST-2', '2026-06-01', 365);

        // owned_to is INCLUSIVE, matching the warranty end-date rule in
        // FR-SN-001-003: on the last day of a period the earlier owner still held
        // the unit, and a claim raised that day must find them.
        $this->assertSame(
            'CUST-1',
            $service->ownerAsAt('SN-106', '2026-03-15')->ownerRef,
            'the final day of a period still belongs to that owner'
        );

        $this->assertSame(
            'CUST-1',
            $service->ownerAsAt('SN-106', '2026-01-01')->ownerRef,
            'the first day of a period is inclusive too'
        );

        $this->assertSame(
            'CUST-2',
            $service->ownerAsAt('SN-106', '2026-06-01')->ownerRef,
            'the new owner takes over on the resale date itself'
        );
    }

    public function testOwnerAsAtBeforeAnyOwnershipIsNull(): void
    {
        $this->sold('SN-107', 'CUST-1', '2026-03-01');

        $this->assertNull($this->service->ownerAsAt('SN-107', '2025-01-01'));
    }

    public function testReturningClosesTheOwnershipPeriod(): void
    {
        $this->sold('SN-108', 'CUST-1', '2026-03-01');

        $this->service->returnSerial('SN-108');   // closes on the injected clock

        $this->assertNull(
            $this->service->currentOwner('SN-108'),
            'an unsold unit has no current owner'
        );
        $this->assertCount(
            1,
            $this->service->ownershipHistory('SN-108'),
            'the closed period is kept, not deleted'
        );
    }

    /**
     * The check that sold_to could never make: the owner is TYPED.
     */
    public function testAnUnrecognisedOwnerKindIsRefused(): void
    {
        $this->service->register('SN-109', 'WIDGET');

        $this->expectException(InvalidSerialStateException::class);
        $this->expectExceptionMessage('Owner kind must be one of');

        $this->service->markSold('SN-109', 'CUST-1', '2026-03-01', 365, 'freetext');
    }

    public function testEveryPermittedOwnerKindIsAccepted(): void
    {
        foreach (OwnershipDto::kinds() as $i => $kind) {
            $serialNo = 'SN-KIND-' . $i;
            $this->service->register($serialNo, 'WIDGET');
            $this->service->markSold($serialNo, 'REF-1', '2026-03-01', 365, $kind);

            $owner = $this->service->currentOwner($serialNo);

            $this->assertSame($kind, $owner->ownerKind);
        }
    }

    public function testAnEmptyOwnerReferenceIsRefused(): void
    {
        $this->service->register('SN-110', 'WIDGET');

        $this->expectException(InvalidSerialStateException::class);
        $this->expectExceptionMessage('owner reference is required');

        $this->service->markSold('SN-110', '   ', '2026-03-01', 365);
    }

    public function testUnitsHeldByFindsEverythingOneOwnerCurrentlyHas(): void
    {
        $this->sold('SN-111', 'CUST-1', '2026-03-01');
        $this->sold('SN-112', 'CUST-1', '2026-03-02');
        $this->sold('SN-113', 'CUST-2', '2026-03-03');

        $held = $this->service->unitsHeldBy(OwnershipDto::KIND_DEBTOR, 'CUST-1');

        $this->assertCount(2, $held);
        $this->assertSame('SN-111', $held[0]->serialNo);
        $this->assertSame('SN-112', $held[1]->serialNo);
    }

    public function testUnitsHeldByRefusesAnUnknownKind(): void
    {
        $this->expectException(InvalidSerialStateException::class);
        $this->service->unitsHeldBy('freetext', 'CUST-1');
    }

    public function testAReturnedUnitIsNoLongerListedAsHeld(): void
    {
        $this->sold('SN-114', 'CUST-1', '2026-03-01');
        $this->service->returnSerial('SN-114');

        $this->assertCount(0, $this->service->unitsHeldBy(OwnershipDto::KIND_DEBTOR, 'CUST-1'));
    }

    /**
     * Refusing beats silently recording nothing, which is how the old design lost
     * the only record of who owned a unit.
     */
    public function testSellingWithoutAnOwnershipRepositoryIsRefusedLoudly(): void
    {
        $service = new SerialNumberService($this->repo, '2026-03-01 09:00:00');
        $service->register('SN-115', 'WIDGET');

        $this->expectException(InvalidSerialStateException::class);
        $this->expectExceptionMessage('No ownership repository configured');

        $service->markSold('SN-115', 'CUST-1', '2026-03-01', 365);
    }

    public function testOwnershipQueriesRefuseAnUnknownSerial(): void
    {
        $this->expectException(\ksfraser\FrontAccounting\SerialNumber\Exception\SerialNotFoundException::class);

        $this->service->currentOwner('SN-DOES-NOT-EXIST');
    }

    public function testDtoRejectsAnInvalidKind(): void
    {
        $this->assertFalse(OwnershipDto::isValidKind('freetext'));
        $this->assertFalse(OwnershipDto::isValidKind(''));
        $this->assertTrue(OwnershipDto::isValidKind(OwnershipDto::KIND_PERSON));
    }

    public function testCoversIsInclusiveOfThePeriodEnd(): void
    {
        $ownership = new OwnershipDto('SN-116', OwnershipDto::KIND_DEBTOR, 'CUST-1', '2026-01-01');
        $ownership->ownedTo = '2026-06-01';

        $this->assertTrue($ownership->covers('2026-06-01'));
        $this->assertFalse($ownership->covers('2026-06-02'));
        $this->assertFalse($ownership->covers('2025-12-31'));
    }
}