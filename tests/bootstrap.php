<?php
/**
 * PHPUnit bootstrap: Composer autoloader plus the minimal FrontAccounting
 * environment the unit tests need.
 */

require __DIR__ . '/../vendor/autoload.php';

// FA transaction types referenced by src/ code paths.
if (!defined('ST_CUSTPAYMENT')) {
    define('ST_CUSTPAYMENT', 12);
}
