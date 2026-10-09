<?php
/**
 * @BABOK Related: FR-SN-005-001, FR-SN-005-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Contracts\ItemControlRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Contracts\SerialRepositoryInterface;
use ksfraser\FrontAccounting\SerialNumber\Dto\CartSerialRequirement;
use ksfraser\FrontAccounting\SerialNumber\Dto\ScanResolution;

/**
 * Work out which serials a delivery still needs, and accept them one at a time.
 *
 * ## Why this exists
 *
 * FA has no cart-line validation hook and `hook_db_postwrite` runs *after* the
 * delivery has been written, immediately before `commit_transaction()`. So there
 * is nowhere in core where a serial-controlled line can be stopped before the
 * document exists. This class is the missing check, and it is deliberately
 * separate from the FA hook so it can be exercised without a database.
 *
 * The rule it enforces: a serial-controlled line needs **one serial per unit of
 * quantity**. `0_sales_order_details` holds a quantity and a stock code, and
 * nothing in FA ties "one serial" to "one unit".
 *
 * ## What it does NOT do
 *
 * It does not move the serials. Committing them belongs at delivery post-write,
 * where the document number finally exists to record against
 * (`DeliverySerialCommit`). Splitting capture from commit is what stops a serial
 * being marked sold for a delivery that then failed to save.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Service
 * @since 1.1.0
 */
class DeliverySerialCapture
{
    /** @var SerialRepositoryInterface */
    private $repo;

    /** @var ItemControlRepositoryInterface */
    private $control;

    /** @var ScanResolver */
    private $scanner;

    /**
     * @param SerialRepositoryInterface     $repo
     * @param ItemControlRepositoryInterface $control
     * @param ScanResolver                  $scanner
     */
    public function __construct(
        SerialRepositoryInterface $repo,
        ItemControlRepositoryInterface $control,
        ScanResolver $scanner
    ) {
        $this->repo = $repo;
        $this->control = $control;
        $this->scanner = $scanner;
    }

    /**
     * Build the requirement set for a set of order lines.
     *
     * Lines for non-serial-controlled items are still returned, with
     * requiresSerial false, so the picker sees the whole cart rather than only
     * the awkward part.
     *
     * @param array $lines Each: ['stock_id' => string, 'qty' => float]
     * @return CartSerialRequirement[] Keyed by item code.
     */
    public function requirements(array $lines): array
    {
        $out = array();

        foreach ($lines as $line) {
            if (!isset($line['stock_id'])) {
                continue;
            }

            $itemCode = (string)$line['stock_id'];
            $qty = isset($line['qty']) ? (float)$line['qty'] : 0.0;

            // Aggregate repeated lines of the same item: two lines of 1 each need
            // two serials, not one per line.
            if (isset($out[$itemCode])) {
                $out[$itemCode]->qty += $qty;
                continue;
            }

            $requirement = new CartSerialRequirement($itemCode, $qty);
            $requirement->requiresSerial = $this->control->requiresSerial($itemCode);
            $requirement->warrantyDays = $this->control->warrantyDays($itemCode);
            $out[$itemCode] = $requirement;
        }

        return $out;
    }

    /**
     * Accept a scanned serial against one line of an order.
     *
     * The whole requirement set is passed, not just one line, because a serial
     * must not satisfy two lines: two customers cannot both own one unit.
     *
     * @param CartSerialRequirement[] $requirements
     * @param string                   $itemCode    Which line it is for.
     * @param string                   $code        As scanned.
     * @return array{accepted:bool,reason:string}
     * @throws \InvalidArgumentException When $itemCode is not one of the lines.
     */
    public function assign(array $requirements, string $itemCode, string $code): array
    {
        if (!isset($requirements[$itemCode])) {
            throw new \InvalidArgumentException('No such line on this order: ' . $itemCode);
        }

        $requirement = $requirements[$itemCode];

        if (!$requirement->requiresSerial) {
            return array('accepted' => false, 'reason' => 'This item is not serial-controlled');
        }

        if ($requirement->isSatisfied()) {
            return array(
                'accepted' => false,
                'reason'   => 'All ' . (int)round($requirement->qty) . ' unit(s) already have serials',
            );
        }

        $resolution = $this->scanner->resolve($code);

        if ($resolution->kind === ScanResolution::KIND_UNKNOWN) {
            return array('accepted' => false, 'reason' => $resolution->reason);
        }

        // Scanning an ITEM code here is a common and harmless mistake: the picker
        // scanned the box rather than the unit. Say so rather than "not found".
        if ($resolution->kind === ScanResolution::KIND_ITEM) {
            return array(
                'accepted' => false,
                'reason'   => 'That is the item code. Scan the serial number instead.',
            );
        }

        if ($resolution->kind === ScanResolution::KIND_ITEM_REQUIRES_SERIAL) {
            return array(
                'accepted' => false,
                'reason'   => 'Scan the serial number for ' . $resolution->itemCode,
            );
        }

        // A serial was scanned. It must belong to THIS line's item.
        if ($resolution->itemCode !== $requirement->itemCode) {
            return array(
                'accepted' => false,
                'reason'   => 'Serial ' . $resolution->serialNo . ' belongs to '
                    . (string)$resolution->itemCode . ', not ' . $requirement->itemCode,
            );
        }

        $serialNo = (string)$resolution->serialNo;

        if (in_array($serialNo, $requirement->assignedSerials, true)) {
            return array(
                'accepted' => false,
                'reason'   => 'Serial ' . $serialNo . ' is already on this line',
            );
        }

        // The same unit must not satisfy two lines: two customers cannot both own
        // one serial.
        $elsewhere = $this->assignedToOtherLine($requirements, $serialNo, $itemCode);

        if ($elsewhere !== null) {
            return array(
                'accepted' => false,
                'reason'   => 'Serial ' . $serialNo . ' is already assigned to '
                    . $elsewhere,
            );
        }

        if (!$resolution->isPickable()) {
            return array(
                'accepted' => false,
                'reason'   => 'Serial ' . $serialNo . ' is ' . (string)$resolution->serialStatus
                    . ' and cannot be picked',
            );
        }

        $requirement->assignedSerials[] = $serialNo;

        return array(
            'accepted' => true,
            'reason'   => 'Serial ' . $serialNo . ' assigned to ' . $requirement->itemCode,
        );
    }

    /**
     * Remove an assigned serial, so a mis-scan can be corrected.
     *
     * @param CartSerialRequirement $requirement
     * @param string                $serialNo
     * @return bool True when it was assigned and has been removed.
     */
    public function unassign(CartSerialRequirement $requirement, string $serialNo): bool
    {
        $index = array_search($serialNo, $requirement->assignedSerials, true);

        if ($index === false) {
            return false;
        }

        array_splice($requirement->assignedSerials, (int)$index, 1);

        return true;
    }

    /**
     * Is every serial-controlled line satisfied?
     *
     * This is the gate: false means the delivery is not safe to commit.
     *
     * @param CartSerialRequirement[] $requirements
     * @return bool
     */
    public function isReady(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if (!$requirement->isSatisfied()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The lines still short of serials.
     *
     * @param CartSerialRequirement[] $requirements
     * @return CartSerialRequirement[]
     */
    public function outstanding(array $requirements): array
    {
        $out = array();

        foreach ($requirements as $itemCode => $requirement) {
            if (!$requirement->isSatisfied()) {
                $out[$itemCode] = $requirement;
            }
        }

        return $out;
    }

    /**
     * A one-line summary for a UI banner.
     *
     * @param CartSerialRequirement[] $requirements
     * @return string
     */
    public function summarise(array $requirements): string
    {
        $outstanding = $this->outstanding($requirements);

        if (empty($outstanding)) {
            return 'All serial-controlled lines have serials';
        }

        $parts = array();
        $total = 0;

        foreach ($outstanding as $requirement) {
            $short = $requirement->shortfall();
            $total += $short;
            $parts[] = $requirement->itemCode . ' (' . $short . ')';
        }

        return $total . ' serial(s) still needed: ' . implode(', ', $parts);
    }

    /**
     * Which other line of this order, if any, already holds this serial?
     *
     * Only in-memory assignments are visible here, so this catches a double-scan
     * within one capture session. Two DIFFERENT orders both claiming one serial
     * cannot be detected here -- only the commit step knows the other document's
     * number, so that check belongs in DeliverySerialCommit.
     *
     * @param CartSerialRequirement[] $requirements
     * @param string                   $serialNo
     * @param string                   $exceptItemCode
     * @return string|null The item code it is already on, or null.
     */
    private function assignedToOtherLine(
        array $requirements,
        string $serialNo,
        string $exceptItemCode
    ): ?string {
        foreach ($requirements as $itemCode => $requirement) {
            if ($itemCode === $exceptItemCode) {
                continue;
            }

            if (in_array($serialNo, $requirement->assignedSerials, true)) {
                return (string)$itemCode;
            }
        }

        return null;
    }
}