<?php
/**
 * @BABOK Related: FR-SN-005-002
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Dto\CartSerialRequirement;

/**
 * Bind captured serials to a delivery once the delivery actually exists.
 *
 * ## Why this is separate from capture
 *
 * FA writes the delivery document, then calls `hook_db_postwrite($delivery,
 * ST_CUSTDELIVERY)`, and `commit_transaction()` is the very next line
 * (`sales/includes/db/sales_delivery_db.inc:200-201`). So the post-write hook is
 * the first moment a delivery has a transaction number — and it is inside the
 * transaction, so raising here rolls the whole delivery back rather than leaving
 * a document whose serials were never recorded.
 *
 * That last point is the reason this class refuses rather than merely warns. If
 * the serials cannot be bound, the delivery must not survive: a machine
 * delivered with no serial recorded is precisely the loss the whole design
 * exists to prevent.
 *
 * What this class deliberately does NOT do is commit the transaction or redirect.
 * `hook_db_postwrite` must not `exit` (it runs immediately before the commit),
 * and `pre_header` is the only safe point for a redirect.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Service
 * @since 1.1.0
 */
class DeliverySerialCommit
{
    /** @var SerialNumberService */
    private $serials;

    /**
     * @param SerialNumberService $serials
     */
    public function __construct(SerialNumberService $serials)
    {
        $this->serials = $serials;
    }

    /**
     * Bind every assigned serial to the delivery document.
     *
     * Each serial is reserved then sold, so the unit leaves `available`, cannot be
     * claimed by a second delivery, and gains an ownership period starting on the
     * delivery date.
     *
     * @param CartSerialRequirement[] $requirements
     * @param int                      $transNo   The delivery transaction number.
     * @param string                   $onDate    'Y-m-d'
     * @param string                   $ownerKind One of OwnershipDto::KIND_*.
     * @param string                   $ownerRef  Who the delivery went to.
     * @return array{committed:int,serials:string[]}
     * @throws \RuntimeException When a serial cannot be bound. The caller is
     *         inside FA's transaction, so this rolls the delivery back.
     */
    public function commit(
        array $requirements,
        int $transNo,
        string $onDate,
        string $ownerKind,
        string $ownerRef
    ): array {
        $committed = array();

        foreach ($requirements as $requirement) {
            if (!$requirement->requiresSerial) {
                continue;
            }

            foreach ($requirement->assignedSerials as $serialNo) {
                $this->serials->reserve($serialNo);
                $this->serials->markSold(
                    $serialNo,
                    $ownerRef,
                    $onDate,
                    $requirement->warrantyDays,
                    $ownerKind
                );

                $committed[] = $serialNo;
            }
        }

        return array('committed' => count($committed), 'serials' => $committed);
    }
}