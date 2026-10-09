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
  -- Full scoped pick face. The BIN is the pick face: the bin is the compartment
  -- a picker reaches into, the shelf is the rack it sits on. See FR-SN-003-001.
  --
  -- loc_code width must match 0_ksf_wh_bin.loc_code, or a safe join is impossible.
  `loc_code`  VARCHAR(5)  DEFAULT NULL,
  `aisle_id`  INT(11)     DEFAULT NULL,
  `shelf_id`  INT(11)     DEFAULT NULL,
  `bin_id`    INT(11)     DEFAULT NULL,
  `batch_no` VARCHAR(50) DEFAULT NULL,
  `supplier_ref` VARCHAR(50) DEFAULT NULL,
  `purchase_date` DATE DEFAULT NULL,
  `purchase_cost` DECIMAL(15,4) DEFAULT NULL,
  `currency` VARCHAR(8) DEFAULT NULL,
  `installed_date` DATE DEFAULT NULL,
  `warranty_end` DATE DEFAULT NULL,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_serial_no` (`serial_no`),
  KEY `idx_face` (`loc_code`,`aisle_id`,`shelf_id`,`bin_id`),
  KEY `idx_status` (`status`),
  KEY `idx_batch` (`batch_no`),
  KEY `idx_item_status` (`item_code`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Append-only audit trail of every location change for a serial.
-- warehouse/aggregate-stock drift is detectable by comparing the last
-- entry here against 0_ksf_serial_numbers.loc_code.
CREATE TABLE IF NOT EXISTS `0_ksf_serial_location_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `serial_no` VARCHAR(64) NOT NULL,
  `from_loc_code` VARCHAR(5)  DEFAULT NULL,
  `to_loc_code`   VARCHAR(5)  DEFAULT NULL,
  `from_aisle_id` INT(11)     DEFAULT NULL,
  `from_shelf_id` INT(11)     DEFAULT NULL,
  `from_bin_id`   INT(11)     DEFAULT NULL,
  `to_aisle_id`   INT(11)     DEFAULT NULL,
  `to_shelf_id`   INT(11)     DEFAULT NULL,
  `to_bin_id`     INT(11)     DEFAULT NULL,
  `reason` VARCHAR(64) DEFAULT NULL,
  `moved_by` VARCHAR(32) DEFAULT NULL,
  `moved_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_serial` (`serial_no`),
  KEY `idx_serial_moved` (`serial_no`,`moved_at`),
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

-- ─────────────────────────────────────────────────────────────────────────────
-- Ownership history (FR-SN-002-001, BR-SN-001-002)
--
-- Replaces a single mutable `sold_to` column, which cannot represent a resale --
-- the case serialised goods actually have. Append-only: every transfer keeps its
-- row, so warranty entitlement is an interval against a specific owner rather
-- than a question about whatever the column currently holds.
--
-- owner_kind is a CLOSED SET. This is the point: `sold_to` was free text that
-- could name a debtor, a branch, a contact or a person, and nothing validated it,
-- so a typo silently orphaned an expensive asset. A typed owner resolves to a
-- real FA record within that kind.
--
-- At most ONE row per serial may have owned_to IS NULL (the current owner).
-- Enforced by uniq_current_owner below.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `0_ksf_serial_ownership` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `serial_no`  VARCHAR(64) NOT NULL,
  `owner_kind` ENUM('debtor','branch','contact','person') NOT NULL,
  `owner_ref`  VARCHAR(64) NOT NULL,
  `owned_from` DATE NOT NULL,
  `owned_to`   DATE DEFAULT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  -- A UNIQUE index treats every NULL as distinct, so UNIQUE(serial_no, owned_to)
  -- would happily allow UNLIMITED open rows for one serial. This generated column
  -- is 1 only while the row is the open one, so the unique key permits exactly
  -- one open owner and any number of closed ones.
  `current_marker` TINYINT(1)
    AS (IF(`owned_to` IS NULL, 1, NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_current_owner` (`serial_no`, `current_marker`),
  KEY `idx_owner` (`owner_kind`,`owner_ref`),
  KEY `idx_serial_from` (`serial_no`,`owned_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
