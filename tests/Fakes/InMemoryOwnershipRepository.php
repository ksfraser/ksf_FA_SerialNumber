<?php
/**
 * @BABOK Related: FR-SN-002-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Fakes;

use ksfraser\FrontAccounting\SerialNumber\Contracts\OwnershipRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\OwnershipDto;

/**
 * In-memory ownership history.
 *
 * Models the same invariant the schema enforces: at most one OPEN period per
 * serial. If this fake allowed two, a test could pass while production data would
 * violate the unique key -- which is precisely why the generated-column
 * `current_marker` was verified against MariaDB before the schema was written.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Fakes
 * @since 1.1.0
 */
class InMemoryOwnershipRepository implements OwnershipRepositoryInterface
{
    /** @var OwnershipDto[] */
    private $rows = array();

    /** @var int */
    private $nextId = 1;

    /**
     * @inheritDoc
     *
     * @throws \RuntimeException When the serial already has an open period, so a
     *         test that opens twice fails loudly rather than passing on state
     *         production would reject.
     */
    public function open(OwnershipDto $ownership): int
    {
        if ($this->current($ownership->serialNo) !== null) {
            throw new \RuntimeException(
                'Serial ' . $ownership->serialNo . ' already has an open ownership period'
            );
        }

        $ownership->ownedTo = null;
        $ownership->id = $this->nextId++;
        $this->rows[] = clone $ownership;

        return $ownership->id;
    }

    /**
     * @inheritDoc
     */
    public function closeCurrent(string $serialNo, string $ownedTo): bool
    {
        foreach ($this->rows as $row) {
            if ($row->serialNo === $serialNo && $row->isCurrent()) {
                $row->ownedTo = $ownedTo;
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function current(string $serialNo): ?OwnershipDto
    {
        $found = null;

        foreach ($this->rows as $row) {
            if ($row->serialNo === $serialNo && $row->isCurrent()) {
                $found = $row;
            }
        }

        return $found === null ? null : clone $found;
    }

    /**
     * @inheritDoc
     */
    public function history(string $serialNo): array
    {
        $out = array();

        foreach ($this->rows as $row) {
            if ($row->serialNo === $serialNo) {
                $out[] = clone $row;
            }
        }

        // Oldest first, matching the repository's ORDER BY.
        usort($out, function (OwnershipDto $a, OwnershipDto $b) {
            return strcmp($a->ownedFrom, $b->ownedFrom) ?: ($a->id <=> $b->id);
        });

        return $out;
    }

    /**
     * @inheritDoc
     */
    public function ownerAsAt(string $serialNo, string $onDate): ?OwnershipDto
    {
        $best = null;

        foreach ($this->rows as $row) {
            if ($row->serialNo === $serialNo && $row->covers($onDate)) {
                if ($best === null || $row->ownedFrom >= $best->ownedFrom) {
                    $best = $row;
                }
            }
        }

        return $best === null ? null : clone $best;
    }

    /**
     * @inheritDoc
     */
    public function heldBy(string $ownerKind, string $ownerRef): array
    {
        $out = array();

        foreach ($this->rows as $row) {
            if ($row->ownerKind === $ownerKind
                && $row->ownerRef === $ownerRef
                && $row->isCurrent()) {
                $out[] = clone $row;
            }
        }

        usort($out, function (OwnershipDto $a, OwnershipDto $b) {
            return strcmp($a->serialNo, $b->serialNo);
        });

        return $out;
    }

    /**
     * Seed an already-closed historical period, for resale-trail tests.
     *
     * @param OwnershipDto $ownership
     * @return void
     */
    public function seed(OwnershipDto $ownership): void
    {
        $ownership->id = $this->nextId++;
        $this->rows[] = clone $ownership;
    }
}
