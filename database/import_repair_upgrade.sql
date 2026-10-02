-- Import compatibility repair for existing installations.
-- Safe to run repeatedly in phpMyAdmin. The PHP build verifies the installed
-- schema before attempting any CREATE/ALTER operation.

CREATE TABLE IF NOT EXISTS `import_batches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `batch_type` varchar(80) NOT NULL,
  `file_name` varchar(190) NOT NULL,
  `status` enum('draft','validated','posted','failed') NOT NULL DEFAULT 'draft',
  `row_count` int NOT NULL DEFAULT 0,
  `created_by` int NOT NULL,
  `notes` text DEFAULT NULL,
  `source_path` varchar(255) DEFAULT NULL,
  `file_sha256` char(64) DEFAULT NULL,
  `confirm_token_hash` char(64) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_import_batches_type` (`batch_type`,`status`),
  KEY `idx_import_batches_owner` (`created_by`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bulk_import_rows` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `import_batch_id` bigint NOT NULL,
  `row_number` int NOT NULL,
  `data_json` longtext NOT NULL,
  `resolved_json` longtext DEFAULT NULL,
  `validation_status` enum('valid','invalid','posted') NOT NULL DEFAULT 'invalid',
  `error_message` text DEFAULT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` bigint DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bulk_import_row` (`import_batch_id`,`row_number`),
  KEY `idx_bulk_import_batch_status` (`import_batch_id`,`validation_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='import_batches' AND COLUMN_NAME='source_path')=0,
  'ALTER TABLE `import_batches` ADD COLUMN `source_path` varchar(255) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='import_batches' AND COLUMN_NAME='file_sha256')=0,
  'ALTER TABLE `import_batches` ADD COLUMN `file_sha256` char(64) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='import_batches' AND COLUMN_NAME='confirm_token_hash')=0,
  'ALTER TABLE `import_batches` ADD COLUMN `confirm_token_hash` char(64) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='import_batches' AND COLUMN_NAME='validated_at')=0,
  'ALTER TABLE `import_batches` ADD COLUMN `validated_at` datetime DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='import_batches' AND COLUMN_NAME='posted_at')=0,
  'ALTER TABLE `import_batches` ADD COLUMN `posted_at` datetime DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='import_batches' AND COLUMN_NAME='posted_by')=0,
  'ALTER TABLE `import_batches` ADD COLUMN `posted_by` int DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;