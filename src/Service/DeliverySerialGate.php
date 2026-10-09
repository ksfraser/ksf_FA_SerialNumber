<?php
/**
 * @BABOK Related: FR-SN-006-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Service;

use ksfraser\FrontAccounting\SerialNumber\Dto\CartSerialRequirement;

/**
 * Decide whether a delivery may proceed, and where to send the picker if not.
 *
 * ## Why a redirect is the only enforcement available
 *
 * FA has no cart-line validation hook and ignores `db_prewrite` return values, so
 * there is no way to veto a delivery from a hook. What *is* available is
 * `hook_invoke_all('pre_header')` at `includes/page/header.inc:132`, which runs
 * before any output and before the submit can complete. A picker who reaches that
 * page with a serial-controlled line still short is redirected to the capture
 * page instead.
 *
 * The DI cart is created at `sales_order_entry.php:62-69` and `page()` is called
 * at line 102, so by the time `pre_header` fires the cart is populated and the
 * requirement set can be computed from it.
 *
 * ## Why this is a separate object
 *
 * The decision and the URL are the only testable part; the session and `header()`
 * call are one-line plumbing in hooks.php. Keeping them apart means the rule
 * ("a short serial-controlled line blocks the delivery") is covered by tests
 * rather than only by clicking through FA.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Service
 * @since 1.1.0
 */
class DeliverySerialGate
{
    /** @var DeliverySerialCapture */
    private $capture;

    /**
     * @param DeliverySerialCapture $capture
     */
    public function __construct(DeliverySerialCapture $capture)
    {
        $this->capture = $capture;
    }

    /**
     * May this delivery proceed?
     *
     * @param CartSerialRequirement[] $requirements
     * @return bool
     */
    public function shouldBlock(array $requirements): bool
    {
        return !$this->capture->isReady($requirements);
    }

    /**
     * Where to send the picker, or null when nothing is blocking.
     *
     * @param CartSerialRequirement[] $requirements
     * @param int                      $orderNo
     * @param string                   $basePath FA's $path_to_root, e.g. '/ksf_fa'.
     * @param string                   $page     The module page to route to.
     * @return string|null
     */
    public function redirectFor(array $requirements, int $orderNo, string $basePath, string $page): ?string
    {
        if (!$this->shouldBlock($requirements)) {
            return null;
        }

        return $basePath . '/' . $page . '?order_no=' . $orderNo;
    }

    /**
     * The message shown on the capture page.
     *
     * @param CartSerialRequirement[] $requirements
     * @return string
     */
    public function messageFor(array $requirements): string
    {
        return $this->capture->summarise($requirements);
    }

    /**
     * Build requirements from FA cart lines.
     *
     * FA line shapes differ by type: an SO/DI line exposes `stock_id` and `qty`,
     * but a call-off or a service line may not have a stock id at all. Anything
     * without one is skipped rather than treated as an unknown item.
     *
     * @param array $cartItems As returned by sales_cart::get_items().
     * @return CartSerialRequirement[]
     */
    public function requirementsFromCart(array $cartItems): array
    {
        $lines = array();

        foreach ($cartItems as $key => $line) {
            $stockId = null;

            if (is_object($line)) {
                if (property_exists($line, 'stock_id')) {
                    $stockId = $line->stock_id;
                } elseif (isset($line->product) && is_object($line->product)) {
                    // An invoice line can hold an object rather than a plain id.
                    $stockId = isset($line->product->stock_id) ? $line->product->stock_id : null;
                }
            } elseif (is_array($line) && isset($line['stock_id'])) {
                $stockId = $line['stock_id'];
            }

            if ($stockId === null || $stockId === '') {
                continue;
            }

            $qty = 0.0;

            if (is_object($line) && property_exists($line, 'qty')) {
                $qty = (float)$line->qty;
            } elseif (is_array($line) && isset($line['qty'])) {
                $qty = (float)$line['qty'];
            }

            $lines[] = array('stock_id' => $stockId, 'qty' => $qty);
        }

        return $this->capture->requirements($lines);
    }
}