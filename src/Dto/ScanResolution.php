<?php
/**
 * @BABOK Related: FR-SN-004-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

// Same namespace: ScanResolution::describe() and isPickable() read
// SerialNumberDto::STATUS_*, so no use statement is needed.

/**
 * What a scanned code turned out to be.
 *
 * A discriminated union expressed as a closed set of KIND_* constants, because
 * PHP 7.3 has no union types and FA has no validation hooks -- so the only way
 * to make "you scanned a serial-controlled item but supplied no serial" a
 * hard, checkable failure is for the resolver to hand back an explicit kind that
 * the caller cannot ignore.
 *
 * Why a union and not a boolean: the three outcomes need different handling.
 * An ordinary item can be picked. A serial-controlled item CANNOT be picked
 * without a serial -- that is the whole point of serial control. And a serial
 * resolves to an SKU *and* a position, which is a different shape entirely.
 *
 * PHP 7.3 compatible: untyped properties, every one carrying a default.
 * See AGENTS_ARCH.md §1.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Dto
 * @since 1.1.0
 */
class ScanResolution
{
    /** A plain stock item. It can be picked with no serial. */
    const KIND_ITEM = 'item';

    /**
     * A serial-controlled item, but no serial was scanned.
     *
     * The caller MUST NOT treat this as pickable. It exists so "why won't this
     * scan work?" has an answer other than silence.
     */
    const KIND_ITEM_REQUIRES_SERIAL = 'item_requires_serial';

    /** A serial was scanned: this resolves to an SKU and a position. */
    const KIND_SERIAL = 'serial';

    /** Nothing matches. */
    const KIND_UNKNOWN = 'unknown';

    /**
     * @var string[] Every kind this DTO can carry.
     *
     * @var string[]
     */
    private const KINDS = array(
        self::KIND_ITEM,
        self::KIND_ITEM_REQUIRES_SERIAL,
        self::KIND_SERIAL,
        self::KIND_UNKNOWN,
    );

    /** @var string One of the KIND_* constants. */
    public $kind = self::KIND_UNKNOWN;

    /** @var string The code that was scanned, trimmed. */
    public $scannedCode = '';

    /** @var string|null FA stock id, for every kind except unknown. */
    public $itemCode = null;

    /** @var string|null Stock description, when known. */
    public $itemDescription = null;

    /** @var string|null The serial, for KIND_SERIAL only. */
    public $serialNo = null;

    /** @var string|null Serial lifecycle status, for KIND_SERIAL only. */
    public $serialStatus = null;

    /** @var string|null Location code, for KIND_SERIAL. */
    public $locCode = null;

    /** @var int|null Aisle index, for KIND_SERIAL. */
    public $aisleId = null;

    /** @var int|null Shelf index, for KIND_SERIAL. */
    public $shelfId = null;

    /**
     * @var int|null Bin index -- the pick face, for KIND_SERIAL.
     *
     * Null when the unit is not shelved, which is why shelved() exists rather
     * than reading this directly.
     */
    public $binId = null;

    /** @var int Default warranty days for the item, when known. */
    public $warrantyDays = 0;

    /** @var string|null 'Y-m-d'; cover end for a scanned serial. */
    public $warrantyEnd = null;

    /** @var string Why it is unknown, for KIND_UNKNOWN. */
    public $reason = '';

    /**
     * @param string $scannedCode
     * @param string $kind
     */
    public function __construct(string $scannedCode = '', string $kind = self::KIND_UNKNOWN)
    {
        $this->scannedCode = $scannedCode;
        $this->kind = $kind;
    }

    /**
     * @param string $kind
     * @return bool
     */
    public static function isValidKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * Can this be picked as it stands?
     *
     * True only for an ordinary item, or for a scanned serial whose unit is
     * actually on hand. A serial-controlled item with no serial is NOT pickable,
     * and neither is a retired unit.
     *
     * @return bool
     */
    public function isPickable(): bool
    {
        if ($this->kind === self::KIND_ITEM) {
            return true;
        }

        if ($this->kind !== self::KIND_SERIAL) {
            return false;
        }

        // A retired unit is not stock, wherever it happens to sit.
        if ($this->serialStatus === SerialNumberDto::STATUS_RETIRED) {
            return false;
        }

        return $this->serialStatus === SerialNumberDto::STATUS_AVAILABLE
            || $this->serialStatus === SerialNumberDto::STATUS_RESERVED;
    }

    /**
     * Does this outcome need a serial before it can proceed?
     *
     * @return bool
     */
    public function needsSerial(): bool
    {
        return $this->kind === self::KIND_ITEM_REQUIRES_SERIAL;
    }

    /**
     * @return bool True when the serial sits on a resolvable pick face.
     */
    public function isShelved(): bool
    {
        return $this->locCode !== null && $this->locCode !== ''
            && $this->aisleId !== null
            && $this->shelfId !== null
            && $this->binId !== null;
    }

    /**
     * The pick face, or null.
     *
     * @return array{loc_code:string,aisle_id:int,shelf_id:int,bin_id:int}|null
     */
    public function pickFace(): ?array
    {
        if (!$this->isShelved()) {
            return null;
        }

        return array(
            'loc_code' => (string)$this->locCode,
            'aisle_id' => (int)$this->aisleId,
            'shelf_id' => (int)$this->shelfId,
            'bin_id'   => (int)$this->binId,
        );
    }

    /**
     * A one-line explanation, suitable for a UI message.
     *
     * @return string
     */
    public function describe(): string
    {
        switch ($this->kind) {
            case self::KIND_ITEM:
                return $this->itemDescription !== null && $this->itemDescription !== ''
                    ? $this->itemDescription
                    : (string)$this->itemCode;

            case self::KIND_ITEM_REQUIRES_SERIAL:
                return 'Scan the serial number for ' . (string)$this->itemCode;

            case self::KIND_SERIAL:
                if (!$this->isShelved()) {
                    return 'Serial ' . (string)$this->serialNo . ' is not shelved';
                }

                return 'Serial ' . (string)$this->serialNo
                    . ' in ' . $this->locCode
                    . ' aisle ' . $this->aisleId
                    . ' shelf ' . $this->shelfId
                    . ' bin ' . $this->binId;

            default:
                return $this->reason !== ''
                    ? $this->reason
                    : 'Not a known item or serial';
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'kind'             => $this->kind,
            'scanned_code'     => $this->scannedCode,
            'item_code'        => $this->itemCode,
            'item_description' => $this->itemDescription,
            'serial_no'        => $this->serialNo,
            'serial_status'    => $this->serialStatus,
            'loc_code'         => $this->locCode,
            'aisle_id'         => $this->aisleId,
            'shelf_id'         => $this->shelfId,
            'bin_id'           => $this->binId,
            'warranty_days'    => $this->warrantyDays,
            'warranty_end'     => $this->warrantyEnd,
            'reason'           => $this->reason,
        );
    }
}