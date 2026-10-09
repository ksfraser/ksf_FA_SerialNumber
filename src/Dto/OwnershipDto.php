<?php
/**
 * @BABOK Related: FR-SN-002-001, BR-SN-001-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

/**
 * One period during which a serialised unit belonged to someone.
 *
 * Rows are append-only. A resale closes the previous row and opens a new one, so
 * the history answers "who was entitled to cover, and when" -- which a single
 * mutable owner column cannot.
 *
 * PHP 7.3 compatible: untyped properties, every one carrying a default. See
 * AGENTS_ARCH.md §1.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Dto
 * @since 1.1.0
 */
class OwnershipDto
{
    /** Closed set of owner kinds. Anything else is a programming error. */
    const KIND_DEBTOR  = 'debtor';
    const KIND_BRANCH  = 'branch';
    const KIND_CONTACT = 'contact';
    const KIND_PERSON  = 'person';

    /**
     * Every permitted owner_kind.
     *
     * @var string[]
     */
    private const KINDS = array(
        self::KIND_DEBTOR,
        self::KIND_BRANCH,
        self::KIND_CONTACT,
        self::KIND_PERSON,
    );

    /** @var int Row id; 0 until inserted. */
    public $id = 0;

    /** @var string The serial this period belongs to. */
    public $serialNo = '';

    /** @var string One of the KIND_* constants. */
    public $ownerKind = self::KIND_DEBTOR;

    /** @var string The id within owner_kind; '' means unassigned. */
    public $ownerRef = '';

    /** @var string 'Y-m-d'; the day ownership began. */
    public $ownedFrom = '';

    /** @var string|null 'Y-m-d'; null while this is the current owner. */
    public $ownedTo = null;

    /** @var string|null Free text. */
    public $note = null;

    /**
     * @param string $serialNo
     * @param string $ownerKind
     * @param string $ownerRef
     * @param string $ownedFrom 'Y-m-d'
     */
    public function __construct(
        string $serialNo = '',
        string $ownerKind = self::KIND_DEBTOR,
        string $ownerRef = '',
        string $ownedFrom = ''
    ) {
        $this->serialNo = $serialNo;
        $this->ownerKind = $ownerKind;
        $this->ownerRef = $ownerRef;
        $this->ownedFrom = $ownedFrom;
    }

    /**
     * Is owner_kind one of the permitted values?
     *
     * This is the guard that `sold_to` never had. That column was free text which
     * could name a debtor, a branch, a contact or a person with nothing to
     * validate it, so a typo silently orphaned an expensive asset.
     *
     * @param string $kind
     * @return bool
     */
    public static function isValidKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * @return string[] The permitted owner kinds.
     */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    /**
     * @return bool True while this row is the current owner.
     */
    public function isCurrent(): bool
    {
        return $this->ownedTo === null;
    }

    /**
     * Was this owner in possession on a given date?
     *
     * Inclusive of both ends: ownership on the final day of the period still
     * counts, matching the warranty-end-date rule in FR-SN-001-003.
     *
     * @param string $onDate 'Y-m-d'
     * @return bool
     */
    public function covers(string $onDate): bool
    {
        if ($onDate < $this->ownedFrom) {
            return false;
        }

        return $this->ownedTo === null || $onDate <= $this->ownedTo;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'         => $this->id,
            'serial_no'  => $this->serialNo,
            'owner_kind' => $this->ownerKind,
            'owner_ref'  => $this->ownerRef,
            'owned_from' => $this->ownedFrom,
            'owned_to'   => $this->ownedTo,
            'note'       => $this->note,
        );
    }
}