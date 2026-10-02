-- Bulk import upgrade for existing installations.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS `bulk_import_rows` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `import_batch_id` bigint(20) NOT NULL,
  `row_number` int(11) NOT NULL,
  `data_json` longtext NOT NULL,
  `resolved_json` longtext DEFAULT NULL,
  `validation_status` enum('valid','invalid','posted') NOT NULL DEFAULT 'invalid',
  `error_message` text DEFAULT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bulk_import_row` (`import_batch_id`,`row_number`),
  KEY `idx_bulk_import_batch_status` (`import_batch_id`,`validation_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
