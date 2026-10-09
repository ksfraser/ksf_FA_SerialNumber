<?php
/**
 * @BABOK Related: FR-SN-001-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Ui;

use ksfraser\FrontAccounting\SerialNumber\Dto\SerialNumberDto;
use ksfraser\FrontAccounting\SerialNumber\Service\BatchNumberService;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Service\WarrantyService;

/**
 * Renders the Serial Numbers page.
 *
 * Presentation only: it calls the services and formats their output, and holds
 * no rules of its own. Keeping the rules in the services is what lets the
 * lifecycle be verified exhaustively without a browser or a database.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Ui
 * @since 1.0.0
 */
class PageController
{
    /** @var SerialNumberService */
    private $serials;

    /** @var WarrantyService */
    private $warranty;

    /** @var BatchNumberService */
    private $batches;

    /**
     * @param SerialNumberService $serials
     * @param WarrantyService     $warranty
     * @param BatchNumberService  $batches
     */
    public function __construct(
        SerialNumberService $serials,
        WarrantyService $warranty,
        BatchNumberService $batches
    ) {
        $this->serials = $serials;
        $this->warranty = $warranty;
        $this->batches = $batches;
    }

    /**
     * Dispatch on the request and render.
     *
     * @return void
     */
    public function run(): void
    {
        $action = isset($_POST['SerialAction']) ? (string)$_POST['SerialAction'] : '';

        echo '<h1>' . _('Serial Numbers') . '</h1>';

        switch ($action) {
            case 'Register':
                $this->handleRegister();
                break;
            case 'Move':
                $this->handleMove();
                break;
            default:
                $this->renderSearchForm();
                break;
        }
    }

    /**
     * @return void
     */
    private function renderSearchForm(): void
    {
        start_form('SerialSearch', $_SERVER['REQUEST_URI'] ?? '', 'post', '', array(), false);

        start_row();
        label_cell(_('Serial number'));
        text_input('serial_no', (string)($_POST['serial_no'] ?? ''), 30);
        end_row();

        start_row();
        label_cell(_('Item code'));
        text_input('item_code', (string)($_POST['item_code'] ?? ''), 20);
        end_row();

        start_row();
        label_cell(_('Location'));
        text_input('loc_code', (string)($_POST['loc_code'] ?? ''), 5);
        end_row();

        submit_row(_('Search'), '', '', 'Search');

        end_form();
    }

    /**
     * @return void
     */
    private function handleRegister(): void
    {
        $serialNo = isset($_POST['serial_no']) ? trim((string)$_POST['serial_no']) : '';
        $itemCode = isset($_POST['item_code']) ? trim((string)$_POST['item_code']) : '';
        $locCode = isset($_POST['loc_code']) ? trim((string)$_POST['loc_code']) : '';

        if ($serialNo === '' || $itemCode === '') {
            display_error(_('A serial number and item code are required.'));
            return;
        }

        $attributes = array();

        if ($locCode !== '') {
            $attributes['locCode'] = $locCode;
        }

        try {
            $serial = $this->serials->register($serialNo, $itemCode, $attributes);
            display_notification(_('Serial registered: ') . $serial->serialNo);
        } catch (Exception $e) {
            display_error($e->getMessage());
        }
    }

    /**
     * @return void
     */
    private function handleMove(): void
    {
        $serialNo = isset($_POST['serial_no']) ? trim((string)$_POST['serial_no']) : '';
        $toLoc = isset($_POST['to_loc_code']) ? trim((string)$_POST['to_loc_code']) : '';

        if ($serialNo === '' || $toLoc === '') {
            display_error(_('A serial number and destination location are required.'));
            return;
        }

        try {
            $serial = $this->serials->move($serialNo, $toLoc, null, 'manual');
            display_notification(
                _('Serial moved to ') . (string)$serial->locCode
            );
        } catch (Exception $e) {
            display_error($e->getMessage());
        }
    }

    /**
     * Render one serial with its warranty state.
     *
     * @param SerialNumberDto $serial
     * @return void
     */
    public function renderSerial(SerialNumberDto $serial): void
    {
        label_row(_('Serial'), $serial->serialNo);
        label_row(_('Item'), $serial->itemCode);
        label_row(_('Status'), $serial->status);
        label_row(_('Location'), (string)$serial->locCode);
        label_row(_('Shelf'), $serial->shelfId === null ? '' : (string)$serial->shelfId);
        label_row(_('Sold to'), (string)$serial->soldTo);

        $covered = $this->warranty->isCovered($serial);
        $days = $this->warranty->daysRemaining($serial);

        label_row(
            _('Warranty'),
            $covered
                ? sprintf(_('Covered (%d days remaining)'), $days)
                : _('Not covered')
        );
    }
}