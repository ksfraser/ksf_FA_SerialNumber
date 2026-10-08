-- ksf_FA_SerialNumber schema.
--
-- FA's db_import() substitutes ONLY the literal "0_" with the company table
-- prefix. It does not understand {TB_PREF}, @TB_PREF@ or {{MDB}} -- an earlier
-- ksf_Inventory used {{MDB}} and would have created literal tables named
-- "{{MDB}}inventory_serial_numbers". Every table here therefore uses a literal
-- 0_ prefix, which FA rewrites per company.
--
-- Scope: serial/batch IDENTITY, LOCATION and WARRANTY FACTS about a unit.
-- Deliberately NOT in scope:
--   * aisle/bin/shelf hierarchy      -> ksf_FA_Warehouse owns it
--   * aggregate stock on hand        -> FA 0_stock_moves / ksf_FA_InventoryCount
--   * RMA cases, claims, liabilities -> ksf_FA_WarrantyManagement owns them
-- This module records where a serial is and whether it is under warranty;
-- it does not model the physical hierarchy or the claims process.

CREATE TABLE IF NOT EXISTS `0_ksf_serial_numbers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `serial_no` VARCHAR(64) NOT NULL,
  `item_code` VARCHAR(20) NOT NULL DEFAULT '',
  `status` VARCHAR(16) NOT NULL DEFAULT 'available',
  `loc_code` VARCHAR(5) DEFAULT NULL,
  `shelf_id` INT(11) DEFAULT NULL,
  `batch_no` VARCHAR(50) DEFAULT NULL,
  `supplier_ref` VARCHAR(50) DEFAULT NULL,
  `purchase_date` DATE DEFAULT NULL,
  `purchase_cost` DECIMAL(15,4) DEFAULT NULL,
  `currency` VARCHAR(8) DEFAULT NULL,
  `sold_to` VARCHAR(64) DEFAULT NULL,
  `sold_date` DATE DEFAULT NULL,
  `installed_date` DATE DEFAULT NULL,
  `warranty_end` DATE DEFAULT NULL,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_serial_no` (`serial_no`),
  KEY `idx_item` (`item_code`),
  KEY `idx_loc` (`loc_code`),
  KEY `idx_status` (`status`),
  KEY `idx_batch` (`batch_no`),
  KEY `idx_shelf` (`shelf_id`),
  KEY `idx_sold_to` (`sold_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Append-only audit trail of every location change for a serial.
-- warehouse/aggregate-stock drift is detectable by comparing the last
-- entry here against 0_ksf_serial_numbers.loc_code.
CREATE TABLE IF NOT EXISTS `0_ksf_serial_location_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `serial_no` VARCHAR(64) NOT NULL,
  `from_loc_code` VARCHAR(5) DEFAULT NULL,
  `to_loc_code` VARCHAR(5) DEFAULT NULL,
  `from_shelf_id` INT(11) DEFAULT NULL,
  `to_shelf_id` INT(11) DEFAULT NULL,
  `reason` VARCHAR(64) DEFAULT NULL,
  `moved_by` VARCHAR(32) DEFAULT NULL,
  `moved_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_serial` (`serial_no`),
  KEY `idx_moved_at` (`moved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `0_ksf_batch_numbers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `batch_no` VARCHAR(50) NOT NULL,
  `item_code` VARCHAR(20) NOT NULL DEFAULT '',
  `qty` DECIMAL(15,3) NOT NULL DEFAULT '0',
  `batch_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `supplier_ref` VARCHAR(50) DEFAULT NULL,
  `location_code` VARCHAR(5) DEFAULT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `notes` TEXT,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_batch_no_item` (`batch_no`, `item_code`),
  KEY `idx_item_expiry` (`item_code`, `expiry_date`),
  KEY `idx_batch_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;