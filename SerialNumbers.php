<?php
/**
 * Serial Numbers - module page entry point.
 *
 * Thin FA page shell: sets up security/session, boots the Composer autoloader
 * and hands off to the page controller. All logic lives in the namespaced
 * controller so it can be unit tested outside a web request.
 *
 * @package ksf_FA_SerialNumber
 *
 * @BABOK Related: BR-SN-001
 */

$path_to_root = '../..';

$page_security = 'SA_ksf_FA_SerialNumberVIEW';
include_once($path_to_root . '/includes/session.inc');
add_access_extensions();

$autoload = dirname(__FILE__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    display_error(_('ksf_FA_SerialNumber: vendor autoload missing. Run "composer install" in the module directory.'));
    end_page();
    exit;
}
require_once $autoload;

use ksfraser\FrontAccounting\SerialNumber\Adapter\FaBatchRepository;
use ksfraser\FrontAccounting\SerialNumber\Adapter\FaSerialRepository;
use ksfraser\FrontAccounting\SerialNumber\Service\BatchNumberService;
use ksfraser\FrontAccounting\SerialNumber\Service\SerialNumberService;
use ksfraser\FrontAccounting\SerialNumber\Service\WarrantyService;
use ksfraser\FrontAccounting\SerialNumber\Ui\PageController;

$serialRepo = new FaSerialRepository();
$batchRepo = new FaBatchRepository();

$controller = new PageController(
    new SerialNumberService($serialRepo),
    new WarrantyService($serialRepo),
    new BatchNumberService($batchRepo)
);

$controller->run();