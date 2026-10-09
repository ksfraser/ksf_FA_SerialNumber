<?php
/**
 * @BABOK Related: FR-SN-004-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Contracts\ItemControlRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Contracts\ItemLookupInterface;
use ksfraser\FrontAccounting\SerialNumber\Contracts\SerialRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\ScanResolution;
use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;

/**
 * Turn a scanned barcode into something the picker can act on.
 *
 * A warehouse scanner produces a string and nothing else. That string may be a
 * serial, an item code, or a barcode that stands for an item. What the caller
 * needs to know is which, and only three useful answers exist:
 *
 *   1. it is a serial          -> here is the SKU and here is the bin
 *   2. it is an ordinary item  -> fine, pick it
 *   3. it is a serial-controlled item, but no serial was scanned
 *                              -> NOT fine; a serial is mandatory
 *
 * The third answer is the whole reason this class exists. FA has no
 * cart-line validation hook (see AGENTS_ARCH.md), so nothing downstream can
 * refuse a serial-controlled line. If the resolver collapsed cases 2 and 3 into
 * a boolean, a machine could be picked with no serial recorded and the loss
 * would only surface at audit.
 *
 * Resolution order is SERIAL first: a serial number is more specific than an
 * item code, and an item code that happens to collide with a serial must resolve
 * to the serial -- the more specific fact wins.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Service
 * @since 1.1.0
 */
class ScanResolver
{
    /** @var SerialRepositoryInterface */
    private $repo;

    /** @var ItemControlRepositoryInterface */
    private $control;

    /** @var ItemLookupInterface */
    private $items;

    /**
     * @param SerialRepositoryInterface     $repo
     * @param ItemControlRepositoryInterface $control
     * @param ItemLookupInterface           $items
     */
    public function __construct(
        SerialRepositoryInterface $repo,
        ItemControlRepositoryInterface $control,
        ItemLookupInterface $items
    ) {
        $this->repo = $repo;
        $this->control = $control;
        $this->items = $items;
    }

    /**
     * Resolve a scanned code.
     *
     * Never throws for an unrecognised code: an unknown scan is a normal event
     * (a damaged label, a code from another system), and it comes back as
     * KIND_UNKNOWN with a reason. Throwing would turn every mistyped scan into a
     * stack trace on the shop floor.
     *
     * @param string $code As scanned; trimmed.
     * @return ScanResolution
     */
    public function resolve(string $code): ScanResolution
    {
        $code = trim($code);

        if ($code === '') {
            $out = new ScanResolution('', ScanResolution::KIND_UNKNOWN);
            $out->reason = 'Nothing was scanned';

            return $out;
        }

        // A serial is the most specific thing a code can be, so it wins.
        $serial = $this->repo->findBySerialNo($code);

        if ($serial !== null) {
            return $this->fromSerial($serial);
        }

        // Not a serial, so it must be an item. An unknown item is UNKNOWN, not
        // "needs a serial": we do not know what it is, so we cannot claim it
        // needs a serial.
        if (!$this->items->exists($code)) {
            $out = new ScanResolution($code, ScanResolution::KIND_UNKNOWN);
            $out->reason = 'No item or serial matches ' . $code;

            return $out;
        }

        $out = new ScanResolution($code, ScanResolution::KIND_ITEM);
        $out->itemCode = $code;
        $out->itemDescription = $this->items->description($code);

        if ($this->control->requiresSerial($code)) {
            // The caller now knows a serial is mandatory for this line.
            $out->kind = ScanResolution::KIND_ITEM_REQUIRES_SERIAL;
        }

        $out->warrantyDays = $this->control->warrantyDays($code);

        return $out;
    }

    /**
     * Serialises a serial scan into the SKU plus the pick face.
     *
     * @param SerialNumberDto $serial
     * @return ScanResolution
     */
    private function fromSerial(SerialNumberDto $serial): ScanResolution
    {
        $out = new ScanResolution($serial->serialNo, ScanResolution::KIND_SERIAL);
        $out->serialNo = $serial->serialNo;
        $out->serialStatus = $serial->status;
        $out->itemCode = $serial->itemCode;
        $out->itemDescription = $this->items->description($serial->itemCode);
        $out->locCode = $serial->locCode;
        $out->aisleId = $serial->aisleId;
        $out->shelfId = $serial->shelfId;
        $out->binId = $serial->binId;
        $out->warrantyEnd = $serial->warrantyEnd;
        $out->warrantyDays = $this->control->warrantyDays($serial->itemCode);

        return $out;
    }
}
