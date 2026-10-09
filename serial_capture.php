<?php
/**
 * Serial capture for a delivery: scan the serials a delivery still needs.
 *
 * FA has no cart-line validation hook, so this page plus the module's pre_header
 * gate is the enforcement path (see ProjectDcs/FR-SN-006-001). The picker arrives
 * here redirected from the sales order entry page, scans each serial, and is
 * returned to the delivery when every serial-controlled line is satisfied.
 *
 * Thin FA page shell, matching SerialNumbers.php: all logic lives in the
 * namespaced controller so it can be unit tested outside a web request.
 *
 * @package ksf_FA_SerialNumber
 *
 * @BABOK Related: FR-SN-006-001, BR-SN-006-002
 */

$path_to_root = '../..';

$page_security = 'SA_ksf_FA_SerialNumberVIEW';
include_once($path_to_root . '/includes/session.inc');
add_access_extensions();

$autoload = dirname(__FILE__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die('<div class="message">Serial capture is unavailable: the module autoloader is missing.</div>');
}
require_once $autoload;

use ksfraser\FrontAccounting\SerialNumber\Service\DeliverySerialCapture;
use ksfraser\FrontAccounting\SerialNumber\Service\DeliverySerialGate;
use ksfraser\FrontAccounting\SerialNumber\Service\ScanResolver;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Adapter\FaItemControlRepository;
use ksfraser\FrontAccounting\SerialNumber\Adapter\FaItemLookup;
use ksfraser\FrontAccounting\SerialNumber\Adapter\FaOwnershipRepository;
use ksfraser\FrontAccounting\SerialNumber\Adapter\FaSerialRepository;

$order_no = isset($_GET['order_no']) && is_numeric($_GET['order_no']) ? (int)$_GET['order_no'] : 0;

if ($order_no <= 0) {
    die('<div class="message">No order was given.</div>');
}

$serial_repo = new FaSerialRepository();
$control = new FaItemControlRepository();
$scanner = new ScanResolver($serial_repo, $control, new FaItemLookup());
$capture = new DeliverySerialCapture($serial_repo, $control, $scanner);
$gate = new DeliverySerialGate($capture);

// ── the order's lines, straight from FA ───────────────────────────────────────
// Every helper used below was checked to exist in this FA tree. TBL_TYPE_LST,
// note_row() and _qty() do NOT exist in 2.4.3, so they are deliberately absent.
if (!function_exists('get_sales_order_details')) {
    display_error(_('Sales order details are unavailable.'));
    end_page();
    exit;
}

$requirements = array();
$result = get_sales_order_details($order_no, ST_SALESORDER);

if ($result) {
    $lines = array();

    while ($line = db_fetch_assoc($result)) {
        if (!isset($line['stk_code'])) {
            continue;
        }

        $lines[] = array(
            'stock_id' => $line['stk_code'],
            // FA's order details call the quantity "quantity", not "qty".
            'qty' => isset($line['quantity']) ? (float)$line['quantity'] : 0.0,
        );
    }

    $requirements = $gate->requirementsFromCart($lines);
}

// ── replay anything already scanned for this order ────────────────────────────
if (!empty($_SESSION['ksf_serial_capture'][$order_no])
    && is_array($_SESSION['ksf_serial_capture'][$order_no])) {
    foreach ($_SESSION['ksf_serial_capture'][$order_no] as $item => $serials) {
        if (!isset($requirements[$item])) {
            continue;
        }

        foreach ((array)$serials as $serial_no) {
            $requirements[$item]->assignedSerials[] = (string)$serial_no;
        }
    }
}

// ── a scan was submitted ──────────────────────────────────────────────────────
if (isset($_POST['scan']) && trim((string)$_POST['scan']) !== '') {
    $scanned = trim((string)$_POST['scan']);
    $resolution = $scanner->resolve($scanned);

    if ($resolution->itemCode === null || !isset($requirements[$resolution->itemCode])) {
        display_error($resolution->describe());
    } else {
        $item_code = (string)$resolution->itemCode;
        $assigned = $capture->assign($requirements, $item_code, $scanned);

        if ($assigned['accepted']) {
            $_SESSION['ksf_serial_capture'][$order_no][$item_code] = array_values(array_unique(
                $requirements[$item_code]->assignedSerials
            ));
            display_notification($assigned['reason']);
        } else {
            display_error($assigned['reason']);
        }
    }
}

// ── mis-scans were removed ────────────────────────────────────────────────────
// The checkbox value is "item|serial" so one submit can clear several lines.
if (isset($_POST['remove']) && is_array($_POST['remove'])) {
    foreach ($_POST['remove'] as $pair) {
        $parts = explode('|', (string)$pair, 2);

        if (count($parts) !== 2) {
            continue;
        }

        $item_code = $parts[0];
        $serial_no = $parts[1];

        if (!isset($requirements[$item_code])) {
            continue;
        }

        $capture->unassign($requirements[$item_code], $serial_no);

        $_SESSION['ksf_serial_capture'][$order_no][$item_code] = array_values(array_unique(
            $requirements[$item_code]->assignedSerials
        ));
    }
}

$ready = !$gate->shouldBlock($requirements);

page(_('Serial Capture'), false, false, '');

echo '<h1>' . _('Serial Capture') . '</h1>';

label_row(_('Order'), (string)$order_no);

if ($ready) {
    label_row(_('Status'), '<b>' . _('Every serial-controlled line has its serials') . '</b>');
} else {
    label_row(_('Outstanding'), '<b>' . htmlspecialchars($gate->messageFor($requirements)) . '</b>');
}

// ── scan box ──────────────────────────────────────────────────────────────────
start_form('', $_SERVER['REQUEST_URI'], 'post', '', array(), false);

start_row();
label_cell(_('Scan a serial'));
text_input('scan', '', 30);
end_row();

submit_row(_('Add serial'), '', '', 'add');

end_form();

// ── what is on this order ─────────────────────────────────────────────────────
// One form wraps the whole list: FA's start_form() emits the <form> tag itself,
// so a form per serial would nest forms, which is invalid HTML and drops the
// inner ones in some browsers. A checkbox per serial avoids both problems and
// needs no JavaScript.
start_form('', $_SERVER['REQUEST_URI'], 'post', '', array(), false);

start_table();
// table_header() opens AND closes its own row, so no end_row() here.
table_header(array(_('Item'), _('Needs'), _('Scanned')));

$any_assigned = false;

foreach ($requirements as $requirement) {
    start_row();

    label_cell($requirement->itemCode);
    label_cell($requirement->requiresSerial ? (string)$requirement->shortfall() : '-');

    if (empty($requirement->assignedSerials)) {
        label_cell('-');
    } else {
        $cells = array();

        foreach ($requirement->assignedSerials as $serial_no) {
            $any_assigned = true;
            $cells[] = '<label><input type="checkbox" name="remove[]" value="'
                . htmlspecialchars($requirement->itemCode . '|' . $serial_no) . '"> '
                . htmlspecialchars($serial_no) . '</label>';
        }

        label_cell(implode('<br>', $cells));
    }

    end_row();
}

end_table();

if ($any_assigned) {
    submit_row(_('Remove selected serials'), 'remove', '', 'remove');
}

end_form();

// ── back to the delivery, once every serial-controlled line is satisfied ──────
if ($ready) {
    start_form('continue-delivery', '../sales/sales_order_entry.php', 'get', '', array(), false);
    hidden('NewDelivery', $order_no);
    submit_row(_('Continue with this delivery'), '', '', 'continue');
    end_form();
} else {
    display_warning(
        _('This delivery cannot be saved until every serial-controlled line has its serials.')
    );
}

end_page();
