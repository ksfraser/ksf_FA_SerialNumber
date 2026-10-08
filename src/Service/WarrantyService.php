<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Contracts\SerialRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;

/**
 * Warranty facts about a serial.
 *
 * This service owns only the *question* "is this unit under warranty, and for
 * how much longer?". It does NOT own claims, RMAs or liabilities -- those live
 * in ksf_FA_WarrantyManagement, which should ask this service rather than
 * reading 0_ksf_serial_numbers directly. That split keeps one authority for
 * warranty dates and avoids two modules disagreeing about cover.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Service
 * @since 1.0.0
 */
class WarrantyService
{
    /** @var SerialRepositoryInterface */
    private $repo;

    /** @var string Frozen 'Y-m-d' for deterministic tests; null uses today. */
    private $today;

    /**
     * @param SerialRepositoryInterface $repo
     * @param string|null $today
     */
    public function __construct(SerialRepositoryInterface $repo, ?string $today = null)
    {
        $this->repo = $repo;
        $this->today = $today;
    }

    /**
     * Is the unit under warranty as at a date?
     *
     * Warranty is inclusive of the end date and requires the unit to actually be
     * installed: a returned or scrapped unit has no running warranty even if a
     * warranty_end date is still sitting on the row, and an available unit has
     * never started one.
     *
     * @param SerialNumberDto $serial
     * @param string|null $onDate 'Y-m-d'; defaults to today.
     * @return bool
     */
    public function isCovered(SerialNumberDto $serial, ?string $onDate = null): bool
    {
        if (!$serial->isWarrantyRunning()) {
            return false;
        }

        if ($serial->warrantyEnd === null || $serial->warrantyEnd === '') {
            return false;
        }

        return $serial->warrantyEnd >= $this->resolveDate($onDate);
    }

    /**
     * Whole days of cover left; 0 once expired or not covered.
     *
     * The day the warranty ends counts as a day of cover, so a unit expiring
     * today returns 1 rather than 0.
     *
     * @param SerialNumberDto $serial
     * @param string|null $onDate
     * @return int
     */
    public function daysRemaining(SerialNumberDto $serial, ?string $onDate = null): int
    {
        if (!$this->isCovered($serial, $onDate)) {
            return 0;
        }

        $now = strtotime($this->resolveDate($onDate));
        $end = strtotime($serial->warrantyEnd);

        return (int)floor(($end - $now) / 86400) + 1;
    }

    /**
     * Extend cover, typically after a paid repair.
     *
     * Extending from an already-expired warranty restarts from the reference
     * date rather than from the old end date, so a repair performed a year late
     * does not create a warranty that is already in the past.
     *
     * @param string $serialNo
     * @param int    $days
     * @param string|null $fromDate 'Y-m-d'; defaults to today.
     * @return SerialNumberDto
     * @throws \ksfraser\FrontAccounting\SerialNumber\Exception\SerialNotFoundException
     */
    public function extend(string $serialNo, int $days, ?string $fromDate = null): SerialNumberDto
    {
        if ($days <= 0) {
            throw new \InvalidArgumentException('Extension days must be positive');
        }

        $serial = $this->repo->findBySerialNo($serialNo);

        if ($serial === null) {
            throw new \ksfraser\FrontAccounting\SerialNumber\Exception\SerialNotFoundException(
                'Serial ' . $serialNo . ' cannot be found'
            );
        }

        $reference = $this->resolveDate($fromDate);
        $base = $this->laterOf($reference, $serial->warrantyEnd);

        $serial->warrantyEnd = date('Y-m-d', strtotime('+' . $days . ' days', strtotime($base)));
        $this->repo->update($serial);

        return $serial;
    }

    /**
     * Installed units with cover expiring within a window -- the renewal worklist.
     *
     * @param string      $itemCode
     * @param int         $withinDays
     * @param string|null $onDate 'Y-m-d'; defaults to today.
     * @return SerialNumberDto[]
     */
    public function expiringSoon(string $itemCode, int $withinDays, ?string $onDate = null): array
    {
        $now = $this->resolveDate($onDate);
        $cutoff = date('Y-m-d', strtotime('+' . $withinDays . ' days', strtotime($now)));
        $out = array();

        foreach ($this->repo->findByItem($itemCode, SerialNumberDto::STATUS_INSTALLED) as $serial) {
            if (!$this->isCovered($serial, $onDate)) {
                continue;
            }

            if ($serial->warrantyEnd !== null && $serial->warrantyEnd <= $cutoff) {
                $out[] = $serial;
            }
        }

        return $out;
    }

    /**
     * @param string|null $onDate
     * @return string 'Y-m-d'
     */
    private function resolveDate(?string $onDate): string
    {
        if ($onDate !== null && $onDate !== '') {
            return $onDate;
        }

        return $this->today !== null ? $this->today : date('Y-m-d');
    }

    /**
     * @param string      $a
     * @param string|null $b
     * @return string
     */
    private function laterOf(string $a, ?string $b): string
    {
        if ($b === null || $b === '') {
            return $a;
        }

        return $b > $a ? $b : $a;
    }
}