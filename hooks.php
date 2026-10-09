<?php
/**
 * KSF FrontAccounting Serial Number Module Hooks.
 *
 * - Menu entry under Items & Inventory ("Serial Numbers").
 * - Security section SS_ksf_FA_SerialNumber with VIEW/MANAGE/WARRANTY areas.
 * - Schema installed from sql/install.sql on activation (literal 0_ tables).
 *
 * Scope boundary (read AGENTS.local.md): this module owns serial/batch IDENTITY,
 * LOCATION and WARRANTY FACTS. It does not own the aisle/bin/shelf hierarchy
 * (ksf_FA_Warehouse), aggregate stock (FA 0_stock_moves / ksf_FA_InventoryCount),
 * or RMA/claims/liabilities (ksf_FA_WarrantyManagement).
 *
 * Capabilities are advertised rather than targeted. Another module asks for a
 * capability by name and any number of providers may answer; see AGENTS_ARCH.md
 * §11. Nothing here hardcodes a partner module.
 *
 * @package ksf_FA_SerialNumber
 * @version 1.0.0
 */

// Security section 156 (free; 145-155 are densely occupied).
define('SS_ksf_FA_SerialNumber', 156 << 8);

/**
 * Hooks for the Serial Number module.
 */
class hooks_ksf_FA_SerialNumber extends hooks
{
    /** @var string Module directory name, matches FA modules/<name>. */
    var $module_name = 'ksf_FA_SerialNumber';

    /** @var string Module version. */
    var $version = '2.4.19-0';

    /**
     * Load the module composer autoloader if present.
     *
     * Lazy on purpose: FA must not fatal when vendor/ has not been installed.
     *
     * @return void
     */
    protected function loadAutoloader()
    {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            return;
        }
        require_once $autoload;
    }

    /**
     * Add a menu item to the Items and Inventory application.
     *
     * @param object $app FA application instance.
     * @return void
     */
    function install_options($app)
    {
        global $path_to_root;

        switch ($app->id) {
            case 'stock':
                $app->add_rapp_function(
                    3,
                    _('Serial Numbers'),
                    $path_to_root . '/modules/' . $this->module_name . '/SerialNumbers.php',
                    'SA_ksf_FA_SerialNumberVIEW'
                );
                break;
        }
    }

    /**
     * Define security areas and sections.
     *
     * @return array [security_areas, security_sections]
     */
    function install_access()
    {
        $security_sections[SS_ksf_FA_SerialNumber] = _("Serial Numbers");

        $security_areas['SA_ksf_FA_SerialNumberVIEW'] = array(
            SS_ksf_FA_SerialNumber | 1,
            _("View serial numbers and their locations")
        );
        $security_areas['SA_ksf_FA_SerialNumberMANAGE'] = array(
            SS_ksf_FA_SerialNumber | 2,
            _("Register, move, sell, return and retire serial numbers")
        );
        $security_areas['SA_ksf_FA_SerialNumberWARRANTY'] = array(
            SS_ksf_FA_SerialNumber | 3,
            _("Adjust warranty and review coverage")
        );

        return array($security_areas, $security_sections);
    }

    /**
     * Activate extension: apply sql/install.sql via FA's update_databases.
     *
     * @param int  $company    Company number.
     * @param bool $check_only Only report whether activation is possible.
     * @return bool
     */
    function activate_extension($company, $check_only = true)
    {
        $this->loadAutoloader();

        if (!file_exists(__DIR__ . '/sql/install.sql')) {
            return true;
        }

        $updates = array('install.sql' => array($this->module_name));
        return $this->update_databases($company, $updates, $check_only);
    }

    /**
     * Provide module constants to other modules.
     *
     * @param array &$data Shared data bag.
     * @param array $opts  Options.
     * @return array
     */
    function getModuleConstants(&$data, $opts = array())
    {
        return array(
            'module_name'   => $this->module_name,
            'version'       => $this->version,
            'security_area' => 'SA_ksf_FA_SerialNumberVIEW',
        );
    }

    /**
     * Advertise module capabilities.
     *
     * @param array &$data Shared data bag.
     * @param array $opts  Options.
     * @return array
     */
    function getModuleCapabilities(&$data, $opts = array())
    {
        return array(
            'serial_register',
            'serial_lookup',
            'serial_at_location',
            'serial_at_face',
            'serial_move',
            'serial_sell',
            'serial_return',
            'serial_retire',
            'serial_history',
            'warranty_cover',
            'warranty_extend',
            'batch_allocate',
            'scan_resolve',
            'serial_control',
            'serial_capture_assign',
            'serial_action',
        );
    }

    /**
     * Answer a specific capability query.
     *
     * @param array &$data Shared data bag; $opts['capability'] selects one.
     * @param array $opts  Options.
     * @return bool|array
     */
    function hasCapability(&$data, $opts = array())
    {
        $capabilities = $this->getModuleCapabilities($data, $opts);

        if (isset($opts['capability'])) {
            return in_array($opts['capability'], $capabilities, true);
        }

        return $capabilities;
    }

    /**
     * Generic capability responder.
     *
     * READ capabilities are broadcast-safe (hook_invoke_all): they only answer.
     * Every returned row carries _module so a consumer can tell providers apart.
     *
     * WRITE capabilities (serial_move, serial_sell, serial_return, serial_retire,
     * serial_register, warranty_extend) deliberately are NOT dispatched via
     * hook_invoke_all -- two modules moving the same serial must not race. Those
     * belong behind hook_invoke_first by the caller, or a direct call.
     *
     * @param array &$data Shared data bag.
     * @param array $opts  Options; $opts['request'] selects the capability.
     * @return array|null Null when this module declines the request.
     */
    function respondToCapabilityRequest(&$data, $opts = array())
    {
        $request = isset($opts['request']) ? $opts['request'] : '';

        $readOnly = array(
            'serial_lookup'      => 'handleLookup',
            'serial_at_location' => 'handleAtLocation',
            'serial_at_face'     => 'handleAtFace',
            'scan_resolve'       => 'handleScanResolve',
            'serial_control'     => 'handleSerialControl',
            'serial_capture_assign' => 'handleCaptureAssign',
            'serial_history'     => 'handleHistory',
            'warranty_cover'     => 'handleWarrantyCover',
            'batch_allocate'     => 'handleBatchAllocate',
        );

        // WRITE actions. These must be reached with hook_invoke_first, never
        // hook_invoke_all: two modules moving the same serial must not race.
        // They live behind one capability so the caller has a single entry point
        // and one place to be careful.
        $writes = array(
            'serial_action' => 'handleSerialAction',
        );

        if (!isset($readOnly[$request]) && !isset($writes[$request])) {
            // Decline: null means "not mine", never false.
            return null;
        }

        $this->loadAutoloader();

        $method = isset($readOnly[$request]) ? $readOnly[$request] : $writes[$request];

        try {
            return $this->{$method}($data, $opts);
        } catch (Exception $e) {
            // A declining responder must not take the caller down with it.
            return null;
        }
    }

    /**
     * This module's root namespace, with a trailing separator.
     *
     * @return string
     */
    private function ns(): string
    {
        return 'ksfraser\\FrontAccounting\\SerialNumber\\';
    }

    /**
     * Build the service and repositories, or null when the vendor is absent.
     *
     * @return array|null [0] SerialNumberService   [1] FaSerialRepository
     *                   [2] FaBatchRepository     [3] WarrantyService
     *                   [4] BatchNumberService    [5] FaOwnershipRepository
     *                   [6] DeliverySerialCapture [7] DeliverySerialGate
     *                   [8] DeliverySerialCommit  [9] FaItemControlRepository
     */
    private function services()
    {
        $ns = $this->ns();

        if (!class_exists($ns . 'Service\\SerialNumberService')) {
            return null;
        }

        $serialRepo = new $ns . 'Adapter\\FaSerialRepository'();
        $batchRepo = new $ns . 'Adapter\\FaBatchRepository'();
        // Ownership is a separate append-only xref, so it gets its own repository.
        // Leaving it null would make every sale refuse -- which is deliberate: a
        // serialised unit sold with no owner recorded is worse than a refusal.
        $ownerRepo = new $ns . 'Adapter\\FaOwnershipRepository'();

        return array(
            new $ns . 'Service\\SerialNumberService'($serialRepo, null, $ownerRepo),
            $serialRepo,
            $batchRepo,
            new $ns . 'Service\\WarrantyService'($serialRepo),
            new $ns . 'Service\\BatchNumberService'($batchRepo),
            $ownerRepo,
        );
    }

    /**
     * serial_lookup: one serial, or null when unknown.
     *
     * @param array &$data
     * @param array $opts
     * @return array|null
     */
    private function handleLookup(&$data, $opts = array())
    {
        $s = $this->services();
        if ($s === null || empty($opts['serial_no'])) {
            return null;
        }

        try {
            $row = $s[0]->get((string)$opts['serial_no'])->toArray();
        } catch (Exception $e) {
            return null;
        }

        $row['_module'] = $this->module_name;
        $row['_entity'] = 'serial_number';

        return $row;
    }

    /**
     * serial_at_location: serials at an FA location.
     *
     * @param array &$data
     * @param array $opts
     * @return array|null
     */
    private function handleAtLocation(&$data, $opts = array())
    {
        $s = $this->services();
        if ($s === null || empty($opts['loc_code'])) {
            return null;
        }

        $status = isset($opts['status']) ? (string)$opts['status'] : null;
        $rows = array();

        foreach ($s[0]->listByLocation((string)$opts['loc_code'], $status) as $serial) {
            $row = $serial->toArray();
            $row['_module'] = $this->module_name;
            $row['_entity'] = 'serial_number';
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * serial_at_face: serials on one warehouse pick face.
     *
     * The WHOLE scoped key is required, not just a shelf: the warehouse's ids are
     * meaningful indices scoped by parent, so shelf 2 of aisle 4 and shelf 2 of
     * aisle 9 are different shelves. A bare shelf_id would return units from
     * every aisle that reuses the number.
     *
     * @param array &$data
     * @param array $opts Requires loc_code, aisle_id, shelf_id, bin_id.
     * @return array|null
     */
    private function handleAtFace(&$data, $opts = array())
    {
        $required = array('loc_code', 'aisle_id', 'shelf_id', 'bin_id');

        foreach ($required as $key) {
            if (!isset($opts[$key])) {
                return null;
            }
        }

        $s = $this->services();
        if ($s === null) {
            return null;
        }

        $rows = array();

        $serials = $s[1]->findByFace(
            (string)$opts['loc_code'],
            (int)$opts['aisle_id'],
            (int)$opts['shelf_id'],
            (int)$opts['bin_id']
        );

        foreach ($serials as $serial) {
            $row = $serial->toArray();
            $row['_module'] = $this->module_name;
            $row['_entity'] = 'serial_number';
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * scan_resolve: turn a scanned code into something pickable.
     *
     * This is the capability other modules need in order to enforce serial
     * capture on a cart line. FA has no cart-line validation hook, so the only
     * place a serial can be required is here.
     *
     * Returns a ScanResolution array whose 'kind' is one of:
     *   item                 -> pickable as scanned
     *   item_requires_serial -> NOT pickable; a serial is mandatory
     *   serial               -> resolves to an SKU and a bin
     *   unknown              -> nothing matches
     *
     * @param array &$data
     * @param array $opts Requires 'code'.
     * @return array|null Null when there is no resolver, so a responder declines
     *         rather than reporting "unknown" for a request it could not answer.
     */
    private function handleScanResolve(&$data, $opts = array())
    {
        if (!isset($opts['code'])) {
            return null;
        }

        $s = $this->services();
        if ($s === null) {
            return null;
        }

        $resolver = new $this->ns() . 'Service\\ScanResolver'(
            $s[1],
            $s[9],
            new $this->ns() . 'Adapter\\FaItemLookup'()
        );

        // Resolved once: calling resolve() twice would mean two database round
        // trips and could disagree if the unit moved between them.
        $resolution = $resolver->resolve((string)$opts['code']);

        $row = $resolution->toArray();
        $row['_module'] = $this->module_name;
        $row['_entity'] = 'scan_resolution';
        $row['_describe'] = $resolution->describe();

        return $row;
    }

    /**
     * serial_control: manage which items are serial-controlled.
     *
     * FA's 0_stock_master has no "needs a serial" flag, so this is how an item
     * is marked. Without it a machine could be picked with no serial recorded.
     *
     * @param array &$data
     * @param array $opts Requires 'item_code'; 'warranty_days' and 'release'.
     * @return array|null
     */
    private function handleSerialControl(&$data, $opts = array())
    {
        if (!isset($opts['item_code'])) {
            return null;
        }

        $s = $this->services();
        if ($s === null) {
            return null;
        }

        $control = new $this->ns() . 'Adapter\\FaItemControlRepository'();
        $itemCode = (string)$opts['item_code'];

        if (!empty($opts['release'])) {
            $control->release($itemCode);
        } else {
            $control->control($itemCode, isset($opts['warranty_days']) ? (int)$opts['warranty_days'] : 0);
        }

        return array(
            'item_code'      => $itemCode,
            'requires_serial' => $control->requiresSerial($itemCode),
            'warranty_days'  => $control->warrantyDays($itemCode),
            '_module'        => $this->module_name,
            '_entity'        => 'serial_control',
        );
    }

    /**
     * serial_history: the location audit trail, newest first.
     *
     * @param array &$data
     * @param array $opts
     * @return array|null
     */
    private function handleHistory(&$data, $opts = array())
    {
        $s = $this->services();
        if ($s === null || empty($opts['serial_no'])) {
            return null;
        }

        $rows = array();

        foreach ($s[0]->history((string)$opts['serial_no']) as $move) {
            $row = $move->toArray();
            $row['_module'] = $this->module_name;
            $row['_entity'] = 'serial_move';
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * warranty_cover: is the unit covered, and for how long.
     *
     * @param array &$data
     * @param array $opts
     * @return array|null
     */
    private function handleWarrantyCover(&$data, $opts = array())
    {
        $s = $this->services();
        if ($s === null || empty($opts['serial_no'])) {
            return null;
        }

        try {
            $serial = $s[0]->get((string)$opts['serial_no']);
        } catch (Exception $e) {
            return null;
        }

        $onDate = isset($opts['on_date']) ? (string)$opts['on_date'] : null;

        return array(
            'serial_no'      => $serial->serialNo,
            'covered'        => $s[3]->isCovered($serial, $onDate),
            'days_remaining' => $s[3]->daysRemaining($serial, $onDate),
            'warranty_end'   => $serial->warrantyEnd,
            '_module'        => $this->module_name,
            '_entity'        => 'warranty_cover',
        );
    }

    /**
     * batch_allocate: FEFO split across batches.
     *
     * Returns a plan only; it does NOT decrement. The caller decides whether to
     * commit, because a partial allocation is a commercial decision (allow the
     * sale short, or block it).
     *
     * @param array &$data
     * @param array $opts
     * @return array|null
     */
    private function handleBatchAllocate(&$data, $opts = array())
    {
        $s = $this->services();
        if ($s === null || empty($opts['item_code']) || !isset($opts['qty'])) {
            return null;
        }

        $onDate = isset($opts['on_date']) ? (string)$opts['on_date'] : date('Y-m-d');
        $requested = (float)$opts['qty'];

        $allocations = array();

        foreach ($s[4]->allocateFefo((string)$opts['item_code'], $requested, $onDate) as $allocation) {
            $allocations[] = $allocation->toArray();
        }

        return array(
            'item_code'  => (string)$opts['item_code'],
            'requested'  => $requested,
            'allocations' => $allocations,
            'shortfall'  => $s[4]->shortfall($allocations, $requested),
            '_module'     => $this->module_name,
            '_entity'     => 'batch_allocation',
        );
    }

    /**
     * pre_header: refuse to let a delivery through while a serial is missing.
     *
     * FA has no cart-line validation hook and ignores db_prewrite return values,
     * so there is no way to veto a delivery from a hook. pre_header is what is
     * left: it runs at includes/page/header.inc:132, before any output and before
     * the submit completes, so redirecting from here is the only enforcement point
     * that exists.
     *
     * The DI cart is created at sales_order_entry.php:62-69 and page() is called
     * at line 102, so the cart is already populated when this fires.
     *
     * This must be cheap and must never loop: it returns immediately unless the
     * current page is the sales order entry page and the cart is a delivery or
     * invoice.
     *
     * @param array $args FA page_header arguments, by reference.
     * @return void
     */
    public function pre_header(&$args)
    {
        if (!$this->isSalesOrderEntryPage()) {
            return;
        }

        $s = $this->services();
        if ($s === null) {
            return;
        }

        $cart = $this->cart();
        if ($cart === null || !in_array($cart->trans_type, array(ST_CUSTDELIVERY, ST_SALESINVOICE), true)) {
            return;
        }

        // Re-apply anything the picker already scanned for THIS order.
        $requirements = $s[7]->requirementsFromCart($cart->get_items());
        $this->reapplyCapturedSerials($requirements, (int)$cart->order_no);

        $url = $s[7]->redirectFor(
            $requirements,
            (int)$cart->order_no,
            $this->pathToRoot(),
            'modules/' . $this->module_name . '/serial_capture.php'
        );

        if ($url === null) {
            return;
        }

        // No output has happened yet at header.inc:132, so this is safe.
        header('Location: ' . $url);
        exit;
    }

    /**
     * db_postwrite: bind the captured serials to the delivery that now exists.
     *
     * FA calls hook_db_postwrite($delivery, ST_CUSTDELIVERY) at
     * sales/includes/db/sales_delivery_db.inc:200 and commit_transaction() is the
     * very next line, so this is the first moment the delivery has a number AND we
     * are still inside the transaction. A failure here rolls the delivery back,
     * which is the behaviour we want: a machine delivered with no serial recorded
     * is the loss this whole design exists to prevent.
     *
     * @param object $delivery   FA sales_cart / sales_order.
     * @param int    $trans_type One of the ST_* constants.
     * @return void
     */
    public function db_postwrite($delivery, $trans_type)
    {
        // ST_CUSTDELIVERY only. An invoice from an already-delivered order has
        // nothing left to capture -- the serials were bound at delivery.
        if ($trans_type !== ST_CUSTDELIVERY) {
            return;
        }

        $s = $this->services();
        if ($s === null || !is_object($delivery)) {
            return;
        }

        $requirements = $s[6]->requirementsFromCart($delivery->get_items());

        if (empty($requirements)) {
            return;
        }

        $this->reapplyCapturedSerials($requirements, (int)$delivery->order_no);

        $serials = $this->collectAssignedSerials($requirements);

        if (empty($serials)) {
            return;
        }

        $ownerRef = (string)$delivery->customer_id;
        $onDate = date('Y-m-d');

        $s[8]->commit($requirements, (int)$delivery->trans_no, $onDate, 'debtor', $ownerRef);

        // The delivery is saved; the picker must not be sent back for the same
        // serials on the next order.
        $this->forgetCapturedSerials((int)$delivery->order_no);
    }

    /**
     * Is the page currently being rendered the sales order entry page?
     *
     * Scoped narrowly on purpose: pre_header fires on EVERY page, so a broad test
     * would either gate unrelated pages or fail to gate this one.
     *
     * @return bool
     */
    private function isSalesOrderEntryPage(): bool
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';

        return basename($script) === 'sales_order_entry.php';
    }

    /**
     * FA's current sales cart, or null when there is not one.
     *
     * @return object|null
     */
    private function cart()
    {
        return isset($_SESSION['Items']) && is_object($_SESSION['Items']) ? $_SESSION['Items'] : null;
    }

    /**
     * @return string
     */
    private function pathToRoot(): string
    {
        global $path_to_root;

        return isset($path_to_root) ? (string)$path_to_root : '';
    }

    /**
     * Session key holding the serials scanned for an order.
     *
     * @return string
     */
    private function captureSessionKey(): string
    {
        return 'ksf_serial_capture';
    }

    /**
     * Copy the picker's scanned serials onto a freshly built requirement set.
     *
     * The requirement set is rebuilt on every page render, so the scanned serials
     * have to be replayed onto it rather than stored on it.
     *
     * @param array $requirements CartSerialRequirement[], by reference.
     * @param int   $orderNo
     * @return void
     */
    private function reapplyCapturedSerials(array &$requirements, int $orderNo)
    {
        if ($orderNo <= 0) {
            return;
        }

        $stored = $this->capturedSerials($orderNo);

        foreach ($stored as $itemCode => $serialNos) {
            if (!isset($requirements[$itemCode])) {
                continue;
            }

            foreach ((array)$serialNos as $serialNo) {
                $requirements[$itemCode]->assignedSerials[] = (string)$serialNo;
            }
        }
    }

    /**
     * @param int $orderNo
     * @return array
     */
    private function capturedSerials(int $orderNo): array
    {
        $key = $this->captureSessionKey();

        if (empty($_SESSION[$key][$orderNo]) || !is_array($_SESSION[$key][$orderNo])) {
            return array();
        }

        return $_SESSION[$key][$orderNo];
    }

    /**
     * @param array $requirements
     * @return string[]
     */
    private function collectAssignedSerials(array $requirements): array
    {
        $out = array();

        foreach ($requirements as $requirement) {
            foreach ($requirement->assignedSerials as $serialNo) {
                $out[] = $serialNo;
            }
        }

        return $out;
    }

    /**
     * @param int $orderNo
     * @return void
     */
    private function forgetCapturedSerials(int $orderNo)
    {
        unset($_SESSION[$this->captureSessionKey()][$orderNo]);
    }

    /**
     * serial_action: perform a write against a serial.
     *
     * ONE entry point for every write, so a caller reaches this with
     * hook_invoke_first and gets a typed action rather than six loosely related
     * capabilities.
     *
     * $opts['action'] is one of:
     *   register  requires serial_no, item_code
     *   move      requires serial_no, loc_code, aisle_id, shelf_id, bin_id
     *   sell      requires serial_no, owner_ref, on_date, warranty_days,
     *             owner_kind
     *   return    requires serial_no
     *   retire    requires serial_no
     *
     * The whole scoped pick face is required for a move: a bare shelf id is
     * ambiguous (FR-SN-003-001).
     *
     * @param array &$data
     * @param array $opts
     * @return array|null Null when FA is not loadable, so the caller knows the
     *         action did NOT happen rather than believing it did.
     */
    private function handleSerialAction(&$data, $opts = array())
    {
        $s = $this->services();
        if ($s === null) {
            return null;
        }

        $serials = $s[0];
        $action = isset($opts['action']) ? (string)$opts['action'] : '';

        try {
            switch ($action) {
                case 'register':
                    $result = $serials->register(
                        isset($opts['serial_no']) ? (string)$opts['serial_no'] : '',
                        isset($opts['item_code']) ? (string)$opts['item_code'] : '',
                        array(
                            'locCode'     => isset($opts['loc_code']) ? $opts['loc_code'] : null,
                            'aisleId'     => isset($opts['aisle_id']) ? (int)$opts['aisle_id'] : null,
                            'shelfId'     => isset($opts['shelf_id']) ? (int)$opts['shelf_id'] : null,
                            'binId'       => isset($opts['bin_id']) ? (int)$opts['bin_id'] : null,
                            'purchaseDate' => isset($opts['purchase_date']) ? $opts['purchase_date'] : null,
                            'purchaseCost' => isset($opts['purchase_cost']) ? (float)$opts['purchase_cost'] : null,
                            'currency'    => isset($opts['currency']) ? $opts['currency'] : null,
                            'supplierRef' => isset($opts['supplier_ref']) ? $opts['supplier_ref'] : null,
                            'warrantyEnd' => isset($opts['warranty_end']) ? $opts['warranty_end'] : null,
                            'notes'       => isset($opts['notes']) ? $opts['notes'] : null,
                        )
                    );
                    break;

                case 'move':
                    $result = $serials->move(
                        isset($opts['serial_no']) ? (string)$opts['serial_no'] : '',
                        isset($opts['loc_code']) ? (string)$opts['loc_code'] : '',
                        isset($opts['aisle_id']) ? (int)$opts['aisle_id'] : null,
                        isset($opts['shelf_id']) ? (int)$opts['shelf_id'] : null,
                        isset($opts['bin_id']) ? (int)$opts['bin_id'] : null,
                        isset($opts['reason']) ? (string)$opts['reason'] : 'transfer'
                    );
                    break;

                case 'sell':
                    $result = $serials->markSold(
                        isset($opts['serial_no']) ? (string)$opts['serial_no'] : '',
                        isset($opts['owner_ref']) ? (string)$opts['owner_ref'] : '',
                        isset($opts['on_date']) ? (string)$opts['on_date'] : date('Y-m-d'),
                        isset($opts['warranty_days']) ? (int)$opts['warranty_days'] : 0,
                        isset($opts['owner_kind']) ? (string)$opts['owner_kind'] : 'debtor'
                    );
                    break;

                case 'return':
                    $result = $serials->returnSerial(
                        isset($opts['serial_no']) ? (string)$opts['serial_no'] : ''
                    );
                    break;

                case 'retire':
                    $result = $serials->retire(
                        isset($opts['serial_no']) ? (string)$opts['serial_no'] : '',
                        isset($opts['reason']) ? (string)$opts['reason'] : 'retired'
                    );
                    break;

                default:
                    // Unknown action: decline, so the caller is not told a write
                    // happened when none did.
                    return null;
            }
        } catch (\Exception $e) {
            // A refused transition is an ANSWER, not a crash: the caller asked for
            // something illegal and needs to know why.
            return array(
                'accepted' => false,
                'reason'   => $e->getMessage(),
                '_entity'  => 'serial_action',
                '_module'  => $this->module_name,
            );
        }

        return array(
            'accepted' => true,
            'action'   => $action,
            'serial'   => $result->toArray(),
            '_entity'  => 'serial_action',
            '_module'  => $this->module_name,
        );
    }

    /**
     * serial_capture_assign: record one scanned serial against an order line.
     *
     * This is what the capture page calls. The gate reads the same session, so
     * recording here is exactly what releases the delivery.
     *
     * @param array &$data
     * @param array $opts Requires 'order_no' and 'code'; optional 'item_code'.
     * @return array|null
     */
    private function handleCaptureAssign(&$data, $opts = array())
    {
        if (!isset($opts['order_no']) || !isset($opts['code'])) {
            return null;
        }

        $s = $this->services();
        if ($s === null) {
            return null;
        }

        $orderNo = (int)$opts['order_no'];
        $requirements = $this->captureRequirementsForOrder($orderNo);

        if ($requirements === null) {
            return null;
        }

        $itemCode = isset($opts['item_code']) ? trim((string)$opts['item_code']) : '';

        // No line named: work the item out from the scan itself.
        if ($itemCode === '') {
            $scanner = new $this->ns() . 'Service\\ScanResolver'(
                $s[1],
                $s[9],
                new $this->ns() . 'Adapter\\FaItemLookup'()
            );

            $scan = $scanner->resolve((string)$opts['code']);

            if ($scan->itemCode === null) {
                return array(
                    'accepted' => false,
                    'reason'   => $scan->describe(),
                    '_entity'  => 'capture',
                    '_module'  => $this->module_name,
                    'order_no' => $orderNo,
                );
            }

            $itemCode = (string)$scan->itemCode;
        }

        $result = $s[6]->assign($requirements, $itemCode, (string)$opts['code']);

        if ($result['accepted']) {
            $this->rememberCapturedSerial($orderNo, $itemCode, $requirements[$itemCode]->assignedSerials);
        }

        $result['_entity'] = 'capture';
        $result['_module'] = $this->module_name;
        $result['order_no'] = $orderNo;
        $result['item_code'] = $itemCode;
        $result['still_needed'] = $s[6]->summarise($requirements);

        return $result;
    }

    /**
     * Remember the serials scanned for an order, keeping the stored list unique.
     *
     * @param int      $orderNo
     * @param string   $itemCode
     * @param string[] $serialNos The whole assigned list for that line.
     * @return void
     */
    private function rememberCapturedSerial(int $orderNo, string $itemCode, array $serialNos): void
    {
        $key = $this->captureSessionKey();

        if (empty($_SESSION[$key][$orderNo])) {
            $_SESSION[$key][$orderNo] = array();
        }

        $_SESSION[$key][$orderNo][$itemCode] = array_values(array_unique($serialNos));
    }

    /**
     * Rebuild an order's requirement set straight from FA's order lines.
     *
     * Reads get_sales_order_details() rather than building a cart: the cart would
     * have to be constructed per document type, and the DI cart already exists for
     * the page being gated anyway.
     *
     * @param int $orderNo
     * @return array|null Null when FA cannot supply the lines.
     */
    private function captureRequirementsForOrder(int $orderNo)
    {
        $s = $this->services();

        if ($s === null || $orderNo <= 0 || !function_exists('get_sales_order_details')) {
            return null;
        }

        // An order with no lines produces no rows, which is a legitimate answer:
        // there is nothing to capture.
        $result = get_sales_order_details($orderNo, ST_SALESORDER);

        if (!$result) {
            return null;
        }

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

        $requirements = $s[7]->requirementsFromCart($lines);
        $this->reapplyCapturedSerials($requirements, $orderNo);

        return $requirements;
    }
}
