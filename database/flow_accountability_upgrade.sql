-- METRICCON Inventory Flow & Accountability Upgrade
-- Import this ONCE into the SAME database used by the website.
-- Designed for MariaDB 10.4+ / Hostinger.

ALTER TABLE `purchase_orders`
  ADD COLUMN IF NOT EXISTS `receiving_location_id` int(11) DEFAULT NULL AFTER `supplier_id`;

-- Backfill legacy POs to the configured/default active warehouse so existing open
-- orders remain receivable after the new receiving-location lock is enabled.
SET @default_receiving_warehouse := (
  SELECT id FROM locations WHERE is_active=1 AND type='warehouse' ORDER BY is_default DESC, id LIMIT 1
);
UPDATE purchase_orders
SET receiving_location_id = @default_receiving_warehouse
WHERE receiving_location_id IS NULL AND @default_receiving_warehouse IS NOT NULL;

ALTER TABLE `stock_counts`
  ADD COLUMN IF NOT EXISTS `recount_of_id` bigint(20) DEFAULT NULL AFTER `location_id`;

ALTER TABLE `workflow_cancellations`
  ADD COLUMN IF NOT EXISTS `decision_remarks` text DEFAULT NULL AFTER `approved_at`;

ALTER TABLE `disposal_writeoffs`
  ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `approval_remarks` text DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `posted_by` int(11) DEFAULT NULL AFTER `approval_remarks`,
  ADD COLUMN IF NOT EXISTS `posted_at` datetime DEFAULT NULL AFTER `posted_by`;

ALTER TABLE `return_to_supplier_records`
  ADD COLUMN IF NOT EXISTS `location_id` int(11) DEFAULT NULL AFTER `material_id`,
  ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `approval_remarks` text DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `shipped_by` int(11) DEFAULT NULL AFTER `approval_remarks`,
  ADD COLUMN IF NOT EXISTS `shipped_at` datetime DEFAULT NULL AFTER `shipped_by`;

CREATE TABLE IF NOT EXISTS `receipt_discrepancies` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `release_id` bigint(20) NOT NULL,
  `release_item_id` bigint(20) NOT NULL,
  `request_item_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `expected_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `received_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `variance_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reason_code` enum('short_delivery','damaged_in_transit','missing','still_in_transit','other') NOT NULL DEFAULT 'short_delivery',
  `reason_details` text DEFAULT NULL,
  `status` enum('open','resolved_received','resolved_returned','resolved_damage','resolved_writeoff','cancelled') NOT NULL DEFAULT 'open',
  `reported_by` int(11) NOT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_discrepancy_item` (`release_item_id`),
  KEY `idx_receipt_discrepancy_status` (`status`,`created_at`),
  KEY `idx_receipt_discrepancy_release` (`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manual_stock_out_requests` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_no` varchar(60) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `reference_no` varchar(120) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` enum('submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `posted_movement_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manual_stock_out_request_no` (`request_no`),
  KEY `idx_manual_stock_out_status` (`status`,`created_at`),
  KEY `idx_manual_stock_out_location` (`location_id`,`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Helpful indexes; duplicate index errors are harmless if your database already has equivalents.
SET @db = DATABASE();
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='purchase_orders' AND index_name='idx_po_receiving_location')=0,
  'ALTER TABLE purchase_orders ADD INDEX idx_po_receiving_location (receiving_location_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='stock_counts' AND index_name='idx_stock_count_recount_of')=0,
  'ALTER TABLE stock_counts ADD INDEX idx_stock_count_recount_of (recount_of_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `return_to_supplier_records`
  MODIFY COLUMN `status` enum('draft','submitted','approved','rejected','shipped','credited','closed','cancelled') NOT NULL DEFAULT 'draft';
