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
            'serial_history'     => 'handleHistory',
            'warranty_cover'     => 'handleWarrantyCover',
            'batch_allocate'     => 'handleBatchAllocate',
        );

        if (!isset($readOnly[$request])) {
            // Decline: null means "not mine", never false.
            return null;
        }

        $this->loadAutoloader();

        $method = $readOnly[$request];

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
     * @return array|null [SerialNumberService, FaSerialRepository, FaBatchRepository,
     *                      WarrantyService, BatchNumberService, FaOwnershipRepository]
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
            new $this->ns() . 'Adapter\\FaItemControlRepository'(),
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
}