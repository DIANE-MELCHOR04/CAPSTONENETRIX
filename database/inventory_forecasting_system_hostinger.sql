-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 22, 2026 at 02:54 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `inventory_forecasting_system`
--

-- Stored procedure clear_system_data omitted for shared-hosting compatibility.


-- --------------------------------------------------------

--
-- Table structure for table `ai_manager_summaries`
--

CREATE TABLE `ai_manager_summaries` (
  `id` bigint(20) NOT NULL,
  `forecast_run_id` bigint(20) DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `summary` text NOT NULL,
  `key_risks` text DEFAULT NULL,
  `recommended_actions` text DEFAULT NULL,
  `source` enum('gemini','rules') NOT NULL DEFAULT 'rules',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_recommendations`
--

CREATE TABLE `ai_recommendations` (
  `id` bigint(20) NOT NULL,
  `forecast_run_id` bigint(20) NOT NULL,
  `forecast_item_id` bigint(20) DEFAULT NULL,
  `material_id` int(11) NOT NULL,
  `title` varchar(180) NOT NULL,
  `urgency` enum('critical','high','medium','stable') NOT NULL DEFAULT 'medium',
  `recommendation` text NOT NULL,
  `reason` text NOT NULL,
  `suggested_action` text NOT NULL,
  `source` enum('gemini','rules') NOT NULL DEFAULT 'rules',
  `status` enum('open','acknowledged','converted','dismissed') NOT NULL DEFAULT 'open',
  `model_name` varchar(120) DEFAULT NULL,
  `prompt_hash` varchar(64) DEFAULT NULL,
  `raw_response_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `attachments`
--

CREATE TABLE `attachments` (
  `id` bigint(20) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `file_name` varchar(190) NOT NULL,
  `stored_path` varchar(255) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `backup_logs`
--

CREATE TABLE `backup_logs` (
  `id` bigint(20) NOT NULL,
  `backup_no` varchar(60) NOT NULL,
  `file_name` varchar(190) NOT NULL,
  `stored_path` varchar(255) DEFAULT NULL,
  `status` enum('completed','failed') NOT NULL,
  `message` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `backup_schedules`
--

CREATE TABLE `backup_schedules` (
  `id` bigint(20) NOT NULL,
  `schedule_name` varchar(120) NOT NULL,
  `frequency` varchar(40) NOT NULL DEFAULT 'daily',
  `run_time` time NOT NULL DEFAULT '22:00:00',
  `retention_days` int(11) NOT NULL DEFAULT 14,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_run_at` datetime DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `barcode_registry`
--

CREATE TABLE `barcode_registry` (
  `id` bigint(20) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `barcode_value` varchar(120) NOT NULL,
  `label_text` varchar(220) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bulk_adjustment_imports`
--

CREATE TABLE `bulk_adjustment_imports` (
  `id` bigint(20) NOT NULL,
  `import_batch_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `adjustment_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(220) NOT NULL,
  `status` enum('pending','approved','posted','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cycle_count_schedules`
--

CREATE TABLE `cycle_count_schedules` (
  `id` bigint(20) NOT NULL,
  `location_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `scheduled_date` date NOT NULL,
  `frequency` varchar(40) NOT NULL DEFAULT 'monthly',
  `status` enum('scheduled','in_progress','completed','missed','cancelled') NOT NULL DEFAULT 'scheduled',
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `damage_reports`
--

CREATE TABLE `damage_reports` (
  `id` bigint(20) NOT NULL,
  `damage_no` varchar(60) NOT NULL,
  `location_id` int(11) NOT NULL,
  `reported_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `reason` text DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `damage_report_items`
--

CREATE TABLE `damage_report_items` (
  `id` bigint(20) NOT NULL,
  `damage_report_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `damaged_qty` decimal(14,2) NOT NULL,
  `decision_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `demand_anomalies`
--

CREATE TABLE `demand_anomalies` (
  `id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) DEFAULT NULL,
  `anomaly_date` date NOT NULL,
  `actual_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `baseline_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `explanation` text NOT NULL,
  `status` enum('open','reviewed','dismissed') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `code` varchar(30) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `disposal_writeoffs`
--

CREATE TABLE `disposal_writeoffs` (
  `id` bigint(20) NOT NULL,
  `writeoff_no` varchar(60) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reason` text NOT NULL,
  `estimated_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `escalation_rules`
--

CREATE TABLE `escalation_rules` (
  `id` bigint(20) NOT NULL,
  `rule_key` varchar(80) NOT NULL,
  `title` varchar(180) NOT NULL,
  `target_role` varchar(60) NOT NULL,
  `threshold_hours` int(11) NOT NULL DEFAULT 24,
  `severity` enum('info','success','warning','danger') NOT NULL DEFAULT 'warning',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forecast_accuracy`
--

CREATE TABLE `forecast_accuracy` (
  `id` bigint(20) NOT NULL,
  `forecast_item_id` bigint(20) DEFAULT NULL,
  `material_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `forecast_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `actual_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `error_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `absolute_percentage_error` decimal(8,2) NOT NULL DEFAULT 0.00,
  `accuracy_score` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forecast_items`
--

CREATE TABLE `forecast_items` (
  `id` bigint(20) NOT NULL,
  `forecast_run_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) DEFAULT NULL,
  `available_stock` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reorder_point` decimal(14,2) NOT NULL DEFAULT 0.00,
  `safety_stock` decimal(14,2) NOT NULL DEFAULT 0.00,
  `forecast_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `average_daily_demand` decimal(14,2) NOT NULL DEFAULT 0.00,
  `projected_stockout_date` date DEFAULT NULL,
  `recommended_order_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `confidence` enum('low','medium','high') NOT NULL DEFAULT 'low',
  `risk_level` enum('critical','high','medium','stable') NOT NULL DEFAULT 'stable',
  `method` varchar(80) NOT NULL DEFAULT 'moving_average',
  `metrics_json` longtext DEFAULT NULL,
  `forecast_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forecast_model_results`
--

CREATE TABLE `forecast_model_results` (
  `id` bigint(20) NOT NULL,
  `forecast_run_id` bigint(20) DEFAULT NULL,
  `material_id` int(11) NOT NULL,
  `model_name` varchar(80) NOT NULL,
  `arima_order` varchar(20) DEFAULT NULL,
  `forecast_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `mae` decimal(14,2) NOT NULL DEFAULT 0.00,
  `rmse` decimal(14,2) NOT NULL DEFAULT 0.00,
  `mape` decimal(8,2) NOT NULL DEFAULT 0.00,
  `confidence_score` decimal(8,2) NOT NULL DEFAULT 0.00,
  `is_selected` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forecast_runs`
--

CREATE TABLE `forecast_runs` (
  `id` bigint(20) NOT NULL,
  `run_no` varchar(60) NOT NULL,
  `run_type` enum('manual','scheduled','test') NOT NULL DEFAULT 'manual',
  `horizon_days` int(11) NOT NULL DEFAULT 30,
  `history_days` int(11) NOT NULL DEFAULT 180,
  `status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
  `started_by` int(11) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `engine` varchar(60) NOT NULL DEFAULT 'statsmodels_arima',
  `notes` text DEFAULT NULL,
  `raw_summary_json` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `import_batches`
--

CREATE TABLE `import_batches` (
  `id` bigint(20) NOT NULL,
  `batch_type` varchar(80) NOT NULL,
  `file_name` varchar(190) NOT NULL,
  `status` enum('draft','validated','posted','failed') NOT NULL DEFAULT 'draft',
  `row_count` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `source_path` varchar(255) DEFAULT NULL,
  `file_sha256` char(64) DEFAULT NULL,
  `confirm_token_hash` char(64) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `inventory_adjustments`
--

CREATE TABLE `inventory_adjustments` (
  `id` bigint(20) NOT NULL,
  `adjustment_no` varchar(60) NOT NULL,
  `location_id` int(11) NOT NULL,
  `adjustment_type` enum('positive','negative','physical_count','encoding_correction','damage','loss','found_stock','other') NOT NULL DEFAULT 'other',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `reason` text NOT NULL,
  `approval_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_adjustment_items`
--

CREATE TABLE `inventory_adjustment_items` (
  `id` bigint(20) NOT NULL,
  `adjustment_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `before_qty` decimal(14,2) NOT NULL,
  `adjustment_qty` decimal(14,2) NOT NULL,
  `after_qty` decimal(14,2) NOT NULL,
  `status` enum('draft','submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_balances`
--

CREATE TABLE `inventory_balances` (
  `id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `quantity_on_hand` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reserved_quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `damaged_quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `in_transit_quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_import_rows`
--

CREATE TABLE `inventory_import_rows` (
  `id` bigint(20) NOT NULL,
  `import_batch_id` bigint(20) NOT NULL,
  `row_number` int(11) NOT NULL,
  `material_code` varchar(60) NOT NULL,
  `location_code` varchar(30) NOT NULL,
  `quantity` decimal(14,2) DEFAULT NULL,
  `reference_no` varchar(120) DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `validation_status` enum('valid','invalid','posted') NOT NULL,
  `error_message` text DEFAULT NULL,
  `movement_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `id` bigint(20) NOT NULL,
  `movement_no` varchar(60) NOT NULL,
  `movement_type` enum('opening','stock_in','stock_out','reservation','release','receipt','adjustment','transfer_dispatch','transfer_receipt','return','damage','supplier_delivery') NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `quantity_in` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity_out` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reference_type` varchar(40) DEFAULT NULL,
  `reference_id` bigint(20) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `line_approval_remarks`
--

CREATE TABLE `line_approval_remarks` (
  `id` bigint(20) NOT NULL,
  `request_item_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `decision` varchar(40) NOT NULL,
  `approved_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `id` int(11) NOT NULL,
  `site_id` int(11) DEFAULT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(180) NOT NULL,
  `type` enum('warehouse','site','quarantine') NOT NULL DEFAULT 'site',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` int(11) NOT NULL,
  `material_code` varchar(60) NOT NULL,
  `material_name` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `unit_id` int(11) NOT NULL,
  `brand_spec` varchar(180) DEFAULT NULL,
  `min_stock` decimal(14,2) NOT NULL DEFAULT 0.00,
  `max_stock` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reorder_point` decimal(14,2) NOT NULL DEFAULT 0.00,
  `safety_stock` decimal(14,2) NOT NULL DEFAULT 0.00,
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `standard_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_critical` tinyint(1) NOT NULL DEFAULT 0,
  `track_lot` tinyint(1) NOT NULL DEFAULT 0,
  `track_expiry` tinyint(1) NOT NULL DEFAULT 0,
  `shelf_life_days` int(11) NOT NULL DEFAULT 0,
  `barcode_value` varchar(120) DEFAULT NULL,
  `preferred_supplier_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `material_categories`
--

CREATE TABLE `material_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `material_lots`
--

CREATE TABLE `material_lots` (
  `id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `lot_no` varchar(80) NOT NULL,
  `batch_no` varchar(80) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('available','reserved','quarantine','expired','consumed') NOT NULL DEFAULT 'available',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_price_history`
--

CREATE TABLE `material_price_history` (
  `id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `effective_date` date NOT NULL,
  `source_doc` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_releases`
--

CREATE TABLE `material_releases` (
  `id` bigint(20) NOT NULL,
  `release_no` varchar(60) NOT NULL,
  `request_id` bigint(20) NOT NULL,
  `source_location_id` int(11) NOT NULL,
  `released_by` int(11) NOT NULL,
  `released_to` varchar(180) DEFAULT NULL,
  `release_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('released','partially_received','received') NOT NULL DEFAULT 'released',
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_release_items`
--

CREATE TABLE `material_release_items` (
  `id` bigint(20) NOT NULL,
  `release_id` bigint(20) NOT NULL,
  `request_item_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `released_qty` decimal(14,2) NOT NULL,
  `received_qty` decimal(14,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_requests`
--

CREATE TABLE `material_requests` (
  `id` bigint(20) NOT NULL,
  `request_no` varchar(60) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `site_id` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `date_needed` date NOT NULL,
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `remarks` text DEFAULT NULL,
  `status` enum('draft','submitted','under_review','approved','partially_approved','rejected','checking_stock','partially_reserved','reserved','ready_for_release','partially_released','released','received','completed','cancelled','on_hold','for_procurement') NOT NULL DEFAULT 'draft',
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_request_items`
--

CREATE TABLE `material_request_items` (
  `id` bigint(20) NOT NULL,
  `request_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `requested_qty` decimal(14,2) NOT NULL,
  `approved_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reserved_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `released_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `received_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `shortage_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `item_status` enum('draft','submitted','approved','partially_approved','rejected','reserved','partially_reserved','for_procurement','partially_released','released','received','completed','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `material_reservations`
--

CREATE TABLE `material_reservations` (
  `id` bigint(20) NOT NULL,
  `request_item_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `reserved_qty` decimal(14,2) NOT NULL,
  `released_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','released','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_returns`
--

CREATE TABLE `material_returns` (
  `id` bigint(20) NOT NULL,
  `return_no` varchar(60) NOT NULL,
  `source_site_id` int(11) NOT NULL,
  `receiving_location_id` int(11) NOT NULL,
  `returned_by` int(11) NOT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','posted','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_return_items`
--

CREATE TABLE `material_return_items` (
  `id` bigint(20) NOT NULL,
  `return_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `returned_qty` decimal(14,2) NOT NULL,
  `condition_status` enum('reusable','partially_damaged','damaged','for_inspection') NOT NULL DEFAULT 'reusable',
  `status` enum('draft','submitted','posted','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `material_transfers`
--

CREATE TABLE `material_transfers` (
  `id` bigint(20) NOT NULL,
  `transfer_no` varchar(60) NOT NULL,
  `source_location_id` int(11) NOT NULL,
  `destination_location_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `dispatched_by` int(11) DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','in_transit','partially_received','received','discrepancy','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `receipt_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_transfer_items`
--

CREATE TABLE `material_transfer_items` (
  `id` bigint(20) NOT NULL,
  `transfer_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `requested_qty` decimal(14,2) NOT NULL,
  `dispatched_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `received_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discrepancy_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','submitted','approved','rejected','in_transit','partially_received','received','discrepancy','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `material_watchlist`
--

CREATE TABLE `material_watchlist` (
  `id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `reason` varchar(220) NOT NULL,
  `severity` enum('info','warning','danger') NOT NULL DEFAULT 'warning',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_key` varchar(190) DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `severity` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `notification_preferences`
--

CREATE TABLE `notification_preferences` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` varchar(80) NOT NULL,
  `in_app_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `quiet_hours_start` time DEFAULT NULL,
  `quiet_hours_end` time DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `number_sequences`
--

CREATE TABLE `number_sequences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sequence_key` varchar(80) NOT NULL,
  `scope_key` varchar(80) NOT NULL DEFAULT 'global',
  `last_value` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `project_budgets`
--

CREATE TABLE `project_budgets` (
  `id` bigint(20) NOT NULL,
  `site_id` int(11) NOT NULL,
  `budget_name` varchar(160) NOT NULL,
  `budget_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `spent_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` bigint(20) NOT NULL,
  `po_no` varchar(60) NOT NULL,
  `purchase_request_id` bigint(20) DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `ordered_by` int(11) NOT NULL,
  `expected_delivery` date DEFAULT NULL,
  `status` enum('submitted','open','partially_received','received','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  `remarks` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` bigint(20) NOT NULL,
  `purchase_order_id` bigint(20) NOT NULL,
  `purchase_request_item_id` bigint(20) DEFAULT NULL,
  `material_id` int(11) NOT NULL,
  `ordered_qty` decimal(14,2) NOT NULL,
  `received_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','partially_received','received','cancelled') NOT NULL DEFAULT 'open',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requests`
--

CREATE TABLE `purchase_requests` (
  `id` bigint(20) NOT NULL,
  `pr_no` varchar(60) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `needed_by` date DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `source_type` enum('manual','material_request','reorder','management') NOT NULL DEFAULT 'manual',
  `ai_recommendation_id` bigint(20) DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','ordered','partially_ordered','partially_received','completed','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_request_items`
--

CREATE TABLE `purchase_request_items` (
  `id` bigint(20) NOT NULL,
  `purchase_request_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `requested_qty` decimal(14,2) NOT NULL,
  `ordered_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `received_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `source_type` enum('manual','material_request','reorder','forecast') NOT NULL DEFAULT 'manual',
  `source_item_id` bigint(20) DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','ordered','partially_ordered','partially_received','completed','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `pwa_offline_drafts`
--

CREATE TABLE `pwa_offline_drafts` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `draft_type` varchar(80) NOT NULL,
  `payload_json` longtext NOT NULL,
  `status` enum('draft','synced','discarded') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `synced_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reorder_suggestions`
--

CREATE TABLE `reorder_suggestions` (
  `id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `suggested_reorder_point` decimal(14,2) NOT NULL DEFAULT 0.00,
  `suggested_safety_stock` decimal(14,2) NOT NULL DEFAULT 0.00,
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `average_daily_demand` decimal(14,2) NOT NULL DEFAULT 0.00,
  `confidence` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` enum('draft','reviewed','applied','dismissed') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_timeline_events`
--

CREATE TABLE `request_timeline_events` (
  `id` bigint(20) NOT NULL,
  `request_id` bigint(20) NOT NULL,
  `event_type` varchar(60) NOT NULL,
  `title` varchar(180) NOT NULL,
  `details` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `return_to_supplier_records`
--

CREATE TABLE `return_to_supplier_records` (
  `id` bigint(20) NOT NULL,
  `return_no` varchar(60) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reason` text NOT NULL,
  `status` enum('draft','submitted','shipped','credited','closed','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saved_filters`
--

CREATE TABLE `saved_filters` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `page_key` varchar(80) NOT NULL,
  `filter_name` varchar(120) NOT NULL,
  `query_string` text NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sites`
--

CREATE TABLE `sites` (
  `id` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(180) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('planning','active','suspended','completed','closed') NOT NULL DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `expected_completion` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `stock_counts`
--

CREATE TABLE `stock_counts` (
  `id` bigint(20) NOT NULL,
  `count_no` varchar(60) NOT NULL,
  `location_id` int(11) NOT NULL,
  `counted_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','approved','adjustment_created','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `approval_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_count_items`
--

CREATE TABLE `stock_count_items` (
  `id` bigint(20) NOT NULL,
  `stock_count_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `system_qty` decimal(14,2) NOT NULL,
  `counted_qty` decimal(14,2) NOT NULL,
  `variance_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','submitted','approved','adjustment_created','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `company` varchar(180) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_deliveries`
--

CREATE TABLE `supplier_deliveries` (
  `id` bigint(20) NOT NULL,
  `delivery_no` varchar(60) NOT NULL,
  `purchase_order_id` bigint(20) NOT NULL,
  `location_id` int(11) NOT NULL,
  `received_by` int(11) NOT NULL,
  `delivery_reference` varchar(120) DEFAULT NULL,
  `status` enum('partial','completed') NOT NULL DEFAULT 'partial',
  `remarks` text DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_delivery_items`
--

CREATE TABLE `supplier_delivery_items` (
  `id` bigint(20) NOT NULL,
  `delivery_id` bigint(20) NOT NULL,
  `purchase_order_item_id` bigint(20) NOT NULL,
  `material_id` int(11) NOT NULL,
  `received_qty` decimal(14,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_quotations`
--

CREATE TABLE `supplier_quotations` (
  `id` bigint(20) NOT NULL,
  `quote_no` varchar(60) NOT NULL,
  `material_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `validity_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('draft','shortlisted','accepted','expired','rejected') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_scorecards`
--

CREATE TABLE `supplier_scorecards` (
  `id` bigint(20) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `po_count` int(11) NOT NULL DEFAULT 0,
  `on_time_rate` decimal(8,2) NOT NULL DEFAULT 0.00,
  `average_lead_time_days` decimal(8,2) NOT NULL DEFAULT 0.00,
  `reliability_score` decimal(8,2) NOT NULL DEFAULT 0.00,
  `late_orders` int(11) NOT NULL DEFAULT 0,
  `variance_rate` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_health_checks`
--

CREATE TABLE `system_health_checks` (
  `id` bigint(20) NOT NULL,
  `check_key` varchar(80) NOT NULL,
  `status` enum('ok','warning','danger') NOT NULL DEFAULT 'ok',
  `message` varchar(255) NOT NULL,
  `checked_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` int(11) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `full_name` varchar(180) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','warehouse_manager','site_engineer','warehouse_personnel','procurement','president') NOT NULL,
  `site_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_snapshots`
--

CREATE TABLE `user_activity_snapshots` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_date` date NOT NULL,
  `login_count` int(11) NOT NULL DEFAULT 0,
  `action_count` int(11) NOT NULL DEFAULT 0,
  `last_action_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `what_if_scenarios`
--

CREATE TABLE `what_if_scenarios` (
  `id` bigint(20) NOT NULL,
  `name` varchar(160) NOT NULL,
  `user_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `location_id` int(11) DEFAULT NULL,
  `starting_stock` decimal(14,2) NOT NULL DEFAULT 0.00,
  `daily_demand` decimal(14,2) NOT NULL DEFAULT 0.00,
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `incoming_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `horizon_days` int(11) NOT NULL DEFAULT 30,
  `result_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workflow_cancellations`
--

CREATE TABLE `workflow_cancellations` (
  `id` bigint(20) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('requested','approved','rejected','voided') NOT NULL DEFAULT 'requested',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_manager_summaries`
--
ALTER TABLE `ai_manager_summaries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ai_manager_summaries_run` (`forecast_run_id`),
  ADD KEY `fk_ai_summary_user` (`created_by`);

--
-- Indexes for table `ai_recommendations`
--
ALTER TABLE `ai_recommendations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ai_recommendation_run` (`forecast_run_id`),
  ADD KEY `idx_ai_recommendation_item` (`forecast_item_id`),
  ADD KEY `idx_ai_recommendation_material` (`material_id`),
  ADD KEY `idx_ai_recommendation_status` (`status`,`urgency`,`created_at`),
  ADD KEY `fk_ai_recommendation_user` (`created_by`);

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `attachments`
--
ALTER TABLE `attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attachment_entity` (`entity_type`,`entity_id`),
  ADD KEY `fk_attachment_user` (`uploaded_by`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_audit_user` (`user_id`,`created_at`);

--
-- Indexes for table `backup_logs`
--
ALTER TABLE `backup_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_backup_no` (`backup_no`),
  ADD KEY `idx_backup_status` (`status`,`created_at`),
  ADD KEY `fk_backup_user` (`created_by`);

--
-- Indexes for table `backup_schedules`
--
ALTER TABLE `backup_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_backup_schedule_name` (`schedule_name`);

--
-- Indexes for table `barcode_registry`
--
ALTER TABLE `barcode_registry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_barcode_registry_value` (`barcode_value`),
  ADD KEY `idx_barcode_registry_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `bulk_adjustment_imports`
--
ALTER TABLE `bulk_adjustment_imports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bulk_adjustments_batch` (`import_batch_id`),
  ADD KEY `fk_bulk_adjustment_material` (`material_id`),
  ADD KEY `fk_bulk_adjustment_location` (`location_id`),
  ADD KEY `fk_bulk_adjustment_user` (`reviewed_by`);

--
-- Indexes for table `cycle_count_schedules`
--
ALTER TABLE `cycle_count_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cycle_count_date` (`scheduled_date`,`status`),
  ADD KEY `fk_cycle_count_location` (`location_id`),
  ADD KEY `fk_cycle_count_material` (`material_id`),
  ADD KEY `fk_cycle_count_user` (`assigned_to`);

--
-- Indexes for table `damage_reports`
--
ALTER TABLE `damage_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_damage_no` (`damage_no`),
  ADD KEY `idx_damage_status` (`status`,`created_at`),
  ADD KEY `fk_damage_location` (`location_id`),
  ADD KEY `fk_damage_reported_by` (`reported_by`),
  ADD KEY `fk_damage_approved_by` (`approved_by`);

--
-- Indexes for table `damage_report_items`
--
ALTER TABLE `damage_report_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_damage_item_report` (`damage_report_id`),
  ADD KEY `fk_damage_item_material` (`material_id`);

--
-- Indexes for table `demand_anomalies`
--
ALTER TABLE `demand_anomalies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_demand_anomalies_material` (`material_id`,`anomaly_date`),
  ADD KEY `fk_anomaly_location` (`location_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_departments_code` (`code`);

--
-- Indexes for table `disposal_writeoffs`
--
ALTER TABLE `disposal_writeoffs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_writeoff_no` (`writeoff_no`),
  ADD KEY `fk_writeoff_material` (`material_id`),
  ADD KEY `fk_writeoff_location` (`location_id`),
  ADD KEY `fk_writeoff_requester` (`requested_by`),
  ADD KEY `fk_writeoff_approver` (`approved_by`);

--
-- Indexes for table `escalation_rules`
--
ALTER TABLE `escalation_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_escalation_rule` (`rule_key`,`target_role`);

--
-- Indexes for table `forecast_accuracy`
--
ALTER TABLE `forecast_accuracy`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_forecast_accuracy_material` (`material_id`,`period_start`),
  ADD KEY `fk_forecast_accuracy_item` (`forecast_item_id`);

--
-- Indexes for table `forecast_items`
--
ALTER TABLE `forecast_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_forecast_item_run` (`forecast_run_id`),
  ADD KEY `idx_forecast_item_material` (`material_id`),
  ADD KEY `idx_forecast_item_location` (`location_id`),
  ADD KEY `idx_forecast_item_risk` (`risk_level`,`recommended_order_qty`);

--
-- Indexes for table `forecast_model_results`
--
ALTER TABLE `forecast_model_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_forecast_model_results_run` (`forecast_run_id`),
  ADD KEY `fk_model_results_material` (`material_id`);

--
-- Indexes for table `forecast_runs`
--
ALTER TABLE `forecast_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_forecast_run_no` (`run_no`),
  ADD KEY `idx_forecast_run_status` (`status`,`started_at`),
  ADD KEY `fk_forecast_run_user` (`started_by`);

--
-- Indexes for table `import_batches`
--
ALTER TABLE `import_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_import_batches_type` (`batch_type`,`status`),
  ADD KEY `idx_import_batches_owner` (`created_by`,`status`,`created_at`),
  ADD KEY `fk_import_batches_posted_by` (`posted_by`);

--
-- Indexes for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_adjustment_no` (`adjustment_no`),
  ADD KEY `idx_adjustment_status` (`status`,`created_at`),
  ADD KEY `fk_adjustment_location` (`location_id`),
  ADD KEY `fk_adjustment_user` (`requested_by`),
  ADD KEY `fk_adjustment_approver` (`approved_by`);

--
-- Indexes for table `inventory_adjustment_items`
--
ALTER TABLE `inventory_adjustment_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adjustment_item_adjustment` (`adjustment_id`),
  ADD KEY `fk_adjustment_item_material` (`material_id`);

--
-- Indexes for table `inventory_balances`
--
ALTER TABLE `inventory_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inventory_material_location` (`material_id`,`location_id`),
  ADD KEY `idx_inventory_location` (`location_id`);

--
-- Indexes for table `inventory_import_rows`
--
ALTER TABLE `inventory_import_rows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inventory_import_row` (`import_batch_id`,`row_number`),
  ADD KEY `idx_inventory_import_status` (`import_batch_id`,`validation_status`),
  ADD KEY `fk_inventory_import_material` (`material_id`),
  ADD KEY `fk_inventory_import_location` (`location_id`),
  ADD KEY `fk_inventory_import_movement` (`movement_id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_movement_no` (`movement_no`),
  ADD KEY `idx_movement_material_location` (`material_id`,`location_id`,`created_at`),
  ADD KEY `idx_movement_reference` (`reference_type`,`reference_id`),
  ADD KEY `fk_movement_location` (`location_id`),
  ADD KEY `fk_movement_user` (`created_by`);

--
-- Indexes for table `line_approval_remarks`
--
ALTER TABLE `line_approval_remarks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_line_remarks_item` (`request_item_id`,`created_at`),
  ADD KEY `fk_line_remarks_user` (`user_id`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_locations_code` (`code`),
  ADD KEY `idx_locations_active` (`is_active`,`name`),
  ADD KEY `fk_locations_site` (`site_id`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_material_code` (`material_code`),
  ADD KEY `idx_material_name` (`material_name`),
  ADD KEY `idx_material_category` (`category_id`),
  ADD KEY `idx_material_approval` (`approval_status`,`is_active`),
  ADD KEY `idx_material_submitter` (`submitted_by`,`approval_status`),
  ADD KEY `fk_material_unit` (`unit_id`),
  ADD KEY `fk_material_supplier` (`preferred_supplier_id`),
  ADD KEY `fk_material_reviewed_by` (`reviewed_by`);

--
-- Indexes for table `material_categories`
--
ALTER TABLE `material_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_material_category` (`name`);

--
-- Indexes for table `material_lots`
--
ALTER TABLE `material_lots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_material_lot_location` (`material_id`,`location_id`,`lot_no`),
  ADD KEY `idx_material_lots_expiry` (`expiry_date`,`status`),
  ADD KEY `fk_material_lots_location` (`location_id`);

--
-- Indexes for table `material_price_history`
--
ALTER TABLE `material_price_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_price_history_material` (`material_id`,`effective_date`),
  ADD KEY `fk_price_history_supplier` (`supplier_id`);

--
-- Indexes for table `material_releases`
--
ALTER TABLE `material_releases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_release_no` (`release_no`),
  ADD KEY `idx_release_request` (`request_id`),
  ADD KEY `fk_release_location` (`source_location_id`),
  ADD KEY `fk_release_user` (`released_by`);

--
-- Indexes for table `material_release_items`
--
ALTER TABLE `material_release_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_release_items_release` (`release_id`),
  ADD KEY `fk_release_item_request_item` (`request_item_id`),
  ADD KEY `fk_release_item_material` (`material_id`);

--
-- Indexes for table `material_requests`
--
ALTER TABLE `material_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_material_request_no` (`request_no`),
  ADD KEY `idx_request_status` (`status`,`request_date`),
  ADD KEY `idx_request_site` (`site_id`),
  ADD KEY `fk_request_user` (`requested_by`),
  ADD KEY `fk_request_reviewer` (`reviewed_by`);

--
-- Indexes for table `material_request_items`
--
ALTER TABLE `material_request_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_items_request` (`request_id`),
  ADD KEY `idx_request_items_material` (`material_id`);

--
-- Indexes for table `material_reservations`
--
ALTER TABLE `material_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reservation_item` (`request_item_id`),
  ADD KEY `idx_reservation_material_location` (`material_id`,`location_id`,`status`),
  ADD KEY `fk_reservation_location` (`location_id`),
  ADD KEY `fk_reservation_user` (`created_by`);

--
-- Indexes for table `material_returns`
--
ALTER TABLE `material_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_return_no` (`return_no`),
  ADD KEY `idx_return_status` (`status`,`created_at`),
  ADD KEY `fk_return_site` (`source_site_id`),
  ADD KEY `fk_return_location` (`receiving_location_id`),
  ADD KEY `fk_return_user` (`returned_by`),
  ADD KEY `fk_return_posted_by` (`posted_by`);

--
-- Indexes for table `material_return_items`
--
ALTER TABLE `material_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_item_return` (`return_id`),
  ADD KEY `fk_return_item_material` (`material_id`);

--
-- Indexes for table `material_transfers`
--
ALTER TABLE `material_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_transfer_no` (`transfer_no`),
  ADD KEY `idx_transfer_status` (`status`,`created_at`),
  ADD KEY `fk_transfer_source` (`source_location_id`),
  ADD KEY `fk_transfer_destination` (`destination_location_id`),
  ADD KEY `fk_transfer_requested_by` (`requested_by`),
  ADD KEY `fk_transfer_approved_by` (`approved_by`),
  ADD KEY `fk_transfer_dispatched_by` (`dispatched_by`),
  ADD KEY `fk_transfer_received_by` (`received_by`);

--
-- Indexes for table `material_transfer_items`
--
ALTER TABLE `material_transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transfer_item_transfer` (`transfer_id`),
  ADD KEY `idx_transfer_item_material` (`material_id`);

--
-- Indexes for table `material_watchlist`
--
ALTER TABLE `material_watchlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_watchlist_material` (`material_id`,`resolved_at`),
  ADD KEY `fk_watchlist_user` (`created_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notification_user_key` (`user_id`,`notification_key`),
  ADD KEY `idx_notification_user` (`user_id`,`read_at`,`created_at`);

--
-- Indexes for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notification_preference` (`user_id`,`notification_type`);

--
-- Indexes for table `number_sequences`
--
ALTER TABLE `number_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_number_sequence_scope` (`sequence_key`,`scope_key`);

--
-- Indexes for table `project_budgets`
--
ALTER TABLE `project_budgets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_budget_site` (`site_id`,`period_start`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_purchase_order_no` (`po_no`),
  ADD KEY `idx_po_status` (`status`,`created_at`),
  ADD KEY `idx_po_supplier` (`supplier_id`),
  ADD KEY `fk_po_pr` (`purchase_request_id`),
  ADD KEY `fk_po_user` (`ordered_by`),
  ADD KEY `fk_po_approver` (`approved_by`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po_item_order` (`purchase_order_id`),
  ADD KEY `idx_po_item_material` (`material_id`),
  ADD KEY `fk_po_item_pr_item` (`purchase_request_item_id`);

--
-- Indexes for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_purchase_request_no` (`pr_no`),
  ADD UNIQUE KEY `uq_pr_ai_recommendation` (`ai_recommendation_id`),
  ADD KEY `idx_purchase_request_status` (`status`,`created_at`),
  ADD KEY `idx_purchase_request_user` (`requested_by`),
  ADD KEY `fk_pr_approver` (`approved_by`);

--
-- Indexes for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pr_item_request` (`purchase_request_id`),
  ADD KEY `idx_pr_item_material` (`material_id`),
  ADD KEY `idx_pr_item_source` (`source_type`,`source_item_id`);

--
-- Indexes for table `pwa_offline_drafts`
--
ALTER TABLE `pwa_offline_drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_offline_drafts_user` (`user_id`,`status`);

--
-- Indexes for table `reorder_suggestions`
--
ALTER TABLE `reorder_suggestions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reorder_suggestions_material` (`material_id`,`status`);

--
-- Indexes for table `request_timeline_events`
--
ALTER TABLE `request_timeline_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_timeline_request` (`request_id`,`created_at`),
  ADD KEY `fk_request_timeline_user` (`created_by`);

--
-- Indexes for table `return_to_supplier_records`
--
ALTER TABLE `return_to_supplier_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_return_supplier_no` (`return_no`),
  ADD KEY `fk_rts_supplier` (`supplier_id`),
  ADD KEY `fk_rts_material` (`material_id`),
  ADD KEY `fk_rts_user` (`created_by`);

--
-- Indexes for table `saved_filters`
--
ALTER TABLE `saved_filters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_saved_filters_user_page` (`user_id`,`page_key`);

--
-- Indexes for table `sites`
--
ALTER TABLE `sites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sites_code` (`code`),
  ADD KEY `idx_sites_status` (`status`,`name`);

--
-- Indexes for table `stock_counts`
--
ALTER TABLE `stock_counts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_count_no` (`count_no`),
  ADD KEY `idx_count_status` (`status`,`created_at`),
  ADD KEY `fk_count_location` (`location_id`),
  ADD KEY `fk_count_user` (`counted_by`),
  ADD KEY `fk_count_approver` (`approved_by`);

--
-- Indexes for table `stock_count_items`
--
ALTER TABLE `stock_count_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_count_item_count` (`stock_count_id`),
  ADD KEY `fk_count_item_material` (`material_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_suppliers_company` (`company`);

--
-- Indexes for table `supplier_deliveries`
--
ALTER TABLE `supplier_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_supplier_delivery_no` (`delivery_no`),
  ADD KEY `idx_delivery_order` (`purchase_order_id`),
  ADD KEY `idx_delivery_status` (`status`,`received_at`),
  ADD KEY `fk_delivery_location` (`location_id`),
  ADD KEY `fk_delivery_user` (`received_by`);

--
-- Indexes for table `supplier_delivery_items`
--
ALTER TABLE `supplier_delivery_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_delivery_item_delivery` (`delivery_id`),
  ADD KEY `fk_delivery_item_order_item` (`purchase_order_item_id`),
  ADD KEY `fk_delivery_item_material` (`material_id`);

--
-- Indexes for table `supplier_quotations`
--
ALTER TABLE `supplier_quotations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_supplier_quote_no` (`quote_no`),
  ADD KEY `fk_supplier_quote_material` (`material_id`),
  ADD KEY `fk_supplier_quote_supplier` (`supplier_id`);

--
-- Indexes for table `supplier_scorecards`
--
ALTER TABLE `supplier_scorecards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier_scorecards_supplier` (`supplier_id`,`period_start`);

--
-- Indexes for table `system_health_checks`
--
ALTER TABLE `system_health_checks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_health_key` (`check_key`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_units_code` (`code`),
  ADD UNIQUE KEY `uq_units_name` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`,`is_active`),
  ADD KEY `fk_users_site` (`site_id`);

--
-- Indexes for table `user_activity_snapshots`
--
ALTER TABLE `user_activity_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_activity_user_date` (`user_id`,`activity_date`);

--
-- Indexes for table `what_if_scenarios`
--
ALTER TABLE `what_if_scenarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_what_if_user` (`user_id`,`created_at`),
  ADD KEY `fk_what_if_material` (`material_id`),
  ADD KEY `fk_what_if_location` (`location_id`);

--
-- Indexes for table `workflow_cancellations`
--
ALTER TABLE `workflow_cancellations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_workflow_cancellations_entity` (`entity_type`,`entity_id`),
  ADD KEY `fk_workflow_cancel_requester` (`requested_by`),
  ADD KEY `fk_workflow_cancel_approver` (`approved_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_manager_summaries`
--
ALTER TABLE `ai_manager_summaries`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_recommendations`
--
ALTER TABLE `ai_recommendations`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attachments`
--
ALTER TABLE `attachments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `backup_logs`
--
ALTER TABLE `backup_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `backup_schedules`
--
ALTER TABLE `backup_schedules`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `barcode_registry`
--
ALTER TABLE `barcode_registry`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bulk_adjustment_imports`
--
ALTER TABLE `bulk_adjustment_imports`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cycle_count_schedules`
--
ALTER TABLE `cycle_count_schedules`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `damage_reports`
--
ALTER TABLE `damage_reports`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `damage_report_items`
--
ALTER TABLE `damage_report_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `demand_anomalies`
--
ALTER TABLE `demand_anomalies`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `disposal_writeoffs`
--
ALTER TABLE `disposal_writeoffs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `escalation_rules`
--
ALTER TABLE `escalation_rules`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_accuracy`
--
ALTER TABLE `forecast_accuracy`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_items`
--
ALTER TABLE `forecast_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_model_results`
--
ALTER TABLE `forecast_model_results`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_runs`
--
ALTER TABLE `forecast_runs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_batches`
--
ALTER TABLE `import_batches`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_adjustment_items`
--
ALTER TABLE `inventory_adjustment_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_balances`
--
ALTER TABLE `inventory_balances`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_import_rows`
--
ALTER TABLE `inventory_import_rows`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `line_approval_remarks`
--
ALTER TABLE `line_approval_remarks`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_categories`
--
ALTER TABLE `material_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_lots`
--
ALTER TABLE `material_lots`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_price_history`
--
ALTER TABLE `material_price_history`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_releases`
--
ALTER TABLE `material_releases`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_release_items`
--
ALTER TABLE `material_release_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_requests`
--
ALTER TABLE `material_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_request_items`
--
ALTER TABLE `material_request_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_reservations`
--
ALTER TABLE `material_reservations`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_returns`
--
ALTER TABLE `material_returns`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_return_items`
--
ALTER TABLE `material_return_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_transfers`
--
ALTER TABLE `material_transfers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_transfer_items`
--
ALTER TABLE `material_transfer_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_watchlist`
--
ALTER TABLE `material_watchlist`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `number_sequences`
--
ALTER TABLE `number_sequences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_budgets`
--
ALTER TABLE `project_budgets`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pwa_offline_drafts`
--
ALTER TABLE `pwa_offline_drafts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reorder_suggestions`
--
ALTER TABLE `reorder_suggestions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_timeline_events`
--
ALTER TABLE `request_timeline_events`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `return_to_supplier_records`
--
ALTER TABLE `return_to_supplier_records`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `saved_filters`
--
ALTER TABLE `saved_filters`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sites`
--
ALTER TABLE `sites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_counts`
--
ALTER TABLE `stock_counts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_count_items`
--
ALTER TABLE `stock_count_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_deliveries`
--
ALTER TABLE `supplier_deliveries`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_delivery_items`
--
ALTER TABLE `supplier_delivery_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_quotations`
--
ALTER TABLE `supplier_quotations`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_scorecards`
--
ALTER TABLE `supplier_scorecards`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_health_checks`
--
ALTER TABLE `system_health_checks`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_activity_snapshots`
--
ALTER TABLE `user_activity_snapshots`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `what_if_scenarios`
--
ALTER TABLE `what_if_scenarios`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workflow_cancellations`
--
ALTER TABLE `workflow_cancellations`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ai_manager_summaries`
--
ALTER TABLE `ai_manager_summaries`
  ADD CONSTRAINT `fk_ai_summary_run` FOREIGN KEY (`forecast_run_id`) REFERENCES `forecast_runs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ai_summary_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ai_recommendations`
--
ALTER TABLE `ai_recommendations`
  ADD CONSTRAINT `fk_ai_recommendation_item` FOREIGN KEY (`forecast_item_id`) REFERENCES `forecast_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ai_recommendation_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_ai_recommendation_run` FOREIGN KEY (`forecast_run_id`) REFERENCES `forecast_runs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ai_recommendation_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `attachments`
--
ALTER TABLE `attachments`
  ADD CONSTRAINT `fk_attachment_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `backup_logs`
--
ALTER TABLE `backup_logs`
  ADD CONSTRAINT `fk_backup_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `bulk_adjustment_imports`
--
ALTER TABLE `bulk_adjustment_imports`
  ADD CONSTRAINT `fk_bulk_adjustment_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bulk_adjustment_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_bulk_adjustment_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_bulk_adjustment_user` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cycle_count_schedules`
--
ALTER TABLE `cycle_count_schedules`
  ADD CONSTRAINT `fk_cycle_count_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_cycle_count_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_cycle_count_user` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `damage_reports`
--
ALTER TABLE `damage_reports`
  ADD CONSTRAINT `fk_damage_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_damage_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_damage_reported_by` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `damage_report_items`
--
ALTER TABLE `damage_report_items`
  ADD CONSTRAINT `fk_damage_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_damage_item_report` FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `demand_anomalies`
--
ALTER TABLE `demand_anomalies`
  ADD CONSTRAINT `fk_anomaly_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_anomaly_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

--
-- Constraints for table `disposal_writeoffs`
--
ALTER TABLE `disposal_writeoffs`
  ADD CONSTRAINT `fk_writeoff_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_writeoff_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_writeoff_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_writeoff_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `forecast_accuracy`
--
ALTER TABLE `forecast_accuracy`
  ADD CONSTRAINT `fk_forecast_accuracy_item` FOREIGN KEY (`forecast_item_id`) REFERENCES `forecast_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_forecast_accuracy_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

--
-- Constraints for table `forecast_items`
--
ALTER TABLE `forecast_items`
  ADD CONSTRAINT `fk_forecast_item_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_forecast_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_forecast_item_run` FOREIGN KEY (`forecast_run_id`) REFERENCES `forecast_runs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `forecast_model_results`
--
ALTER TABLE `forecast_model_results`
  ADD CONSTRAINT `fk_model_results_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_model_results_run` FOREIGN KEY (`forecast_run_id`) REFERENCES `forecast_runs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `forecast_runs`
--
ALTER TABLE `forecast_runs`
  ADD CONSTRAINT `fk_forecast_run_user` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `import_batches`
--
ALTER TABLE `import_batches`
  ADD CONSTRAINT `fk_import_batches_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_import_batches_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  ADD CONSTRAINT `fk_adjustment_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_adjustment_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_adjustment_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `inventory_adjustment_items`
--
ALTER TABLE `inventory_adjustment_items`
  ADD CONSTRAINT `fk_adjustment_item_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_adjustment_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

--
-- Constraints for table `inventory_balances`
--
ALTER TABLE `inventory_balances`
  ADD CONSTRAINT `fk_inventory_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_inventory_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

--
-- Constraints for table `inventory_import_rows`
--
ALTER TABLE `inventory_import_rows`
  ADD CONSTRAINT `fk_inventory_import_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_import_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inventory_import_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inventory_import_movement` FOREIGN KEY (`movement_id`) REFERENCES `inventory_movements` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `fk_movement_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_movement_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_movement_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `line_approval_remarks`
--
ALTER TABLE `line_approval_remarks`
  ADD CONSTRAINT `fk_line_remarks_item` FOREIGN KEY (`request_item_id`) REFERENCES `material_request_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_line_remarks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `locations`
--
ALTER TABLE `locations`
  ADD CONSTRAINT `fk_locations_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`);

--
-- Constraints for table `materials`
--
ALTER TABLE `materials`
  ADD CONSTRAINT `fk_material_category` FOREIGN KEY (`category_id`) REFERENCES `material_categories` (`id`),
  ADD CONSTRAINT `fk_material_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_material_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_material_supplier` FOREIGN KEY (`preferred_supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_material_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`);

--
-- Constraints for table `material_lots`
--
ALTER TABLE `material_lots`
  ADD CONSTRAINT `fk_material_lots_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_material_lots_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

--
-- Constraints for table `material_price_history`
--
ALTER TABLE `material_price_history`
  ADD CONSTRAINT `fk_price_history_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_price_history_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `material_releases`
--
ALTER TABLE `material_releases`
  ADD CONSTRAINT `fk_release_location` FOREIGN KEY (`source_location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_release_request` FOREIGN KEY (`request_id`) REFERENCES `material_requests` (`id`),
  ADD CONSTRAINT `fk_release_user` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `material_release_items`
--
ALTER TABLE `material_release_items`
  ADD CONSTRAINT `fk_release_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_release_item_release` FOREIGN KEY (`release_id`) REFERENCES `material_releases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_release_item_request_item` FOREIGN KEY (`request_item_id`) REFERENCES `material_request_items` (`id`);

--
-- Constraints for table `material_requests`
--
ALTER TABLE `material_requests`
  ADD CONSTRAINT `fk_request_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_request_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`),
  ADD CONSTRAINT `fk_request_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `material_request_items`
--
ALTER TABLE `material_request_items`
  ADD CONSTRAINT `fk_request_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_request_item_request` FOREIGN KEY (`request_id`) REFERENCES `material_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `material_reservations`
--
ALTER TABLE `material_reservations`
  ADD CONSTRAINT `fk_reservation_item` FOREIGN KEY (`request_item_id`) REFERENCES `material_request_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_reservation_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_reservation_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_reservation_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `material_returns`
--
ALTER TABLE `material_returns`
  ADD CONSTRAINT `fk_return_location` FOREIGN KEY (`receiving_location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_return_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_return_site` FOREIGN KEY (`source_site_id`) REFERENCES `sites` (`id`),
  ADD CONSTRAINT `fk_return_user` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `material_return_items`
--
ALTER TABLE `material_return_items`
  ADD CONSTRAINT `fk_return_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_return_item_return` FOREIGN KEY (`return_id`) REFERENCES `material_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `material_transfers`
--
ALTER TABLE `material_transfers`
  ADD CONSTRAINT `fk_transfer_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_transfer_destination` FOREIGN KEY (`destination_location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_transfer_dispatched_by` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_transfer_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_transfer_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_transfer_source` FOREIGN KEY (`source_location_id`) REFERENCES `locations` (`id`);

--
-- Constraints for table `material_transfer_items`
--
ALTER TABLE `material_transfer_items`
  ADD CONSTRAINT `fk_transfer_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_transfer_item_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `material_transfers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `material_watchlist`
--
ALTER TABLE `material_watchlist`
  ADD CONSTRAINT `fk_watchlist_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_watchlist_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD CONSTRAINT `fk_notification_preference_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_budgets`
--
ALTER TABLE `project_budgets`
  ADD CONSTRAINT `fk_project_budget_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`);

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_pr` FOREIGN KEY (`purchase_request_id`) REFERENCES `purchase_requests` (`id`),
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_po_user` FOREIGN KEY (`ordered_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_po_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_po_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_po_item_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_po_item_pr_item` FOREIGN KEY (`purchase_request_item_id`) REFERENCES `purchase_request_items` (`id`);

--
-- Constraints for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD CONSTRAINT `fk_pr_ai_recommendation` FOREIGN KEY (`ai_recommendation_id`) REFERENCES `ai_recommendations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pr_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  ADD CONSTRAINT `fk_pr_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_pr_item_request` FOREIGN KEY (`purchase_request_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pwa_offline_drafts`
--
ALTER TABLE `pwa_offline_drafts`
  ADD CONSTRAINT `fk_offline_drafts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reorder_suggestions`
--
ALTER TABLE `reorder_suggestions`
  ADD CONSTRAINT `fk_reorder_suggestions_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

--
-- Constraints for table `request_timeline_events`
--
ALTER TABLE `request_timeline_events`
  ADD CONSTRAINT `fk_request_timeline_request` FOREIGN KEY (`request_id`) REFERENCES `material_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_request_timeline_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `return_to_supplier_records`
--
ALTER TABLE `return_to_supplier_records`
  ADD CONSTRAINT `fk_rts_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_rts_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_rts_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `saved_filters`
--
ALTER TABLE `saved_filters`
  ADD CONSTRAINT `fk_saved_filters_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_counts`
--
ALTER TABLE `stock_counts`
  ADD CONSTRAINT `fk_count_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_count_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_count_user` FOREIGN KEY (`counted_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `stock_count_items`
--
ALTER TABLE `stock_count_items`
  ADD CONSTRAINT `fk_count_item_count` FOREIGN KEY (`stock_count_id`) REFERENCES `stock_counts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_count_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`);

--
-- Constraints for table `supplier_deliveries`
--
ALTER TABLE `supplier_deliveries`
  ADD CONSTRAINT `fk_delivery_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_delivery_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`),
  ADD CONSTRAINT `fk_delivery_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `supplier_delivery_items`
--
ALTER TABLE `supplier_delivery_items`
  ADD CONSTRAINT `fk_delivery_item_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `supplier_deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_delivery_item_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_delivery_item_order_item` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`);

--
-- Constraints for table `supplier_quotations`
--
ALTER TABLE `supplier_quotations`
  ADD CONSTRAINT `fk_supplier_quote_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_supplier_quote_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `supplier_scorecards`
--
ALTER TABLE `supplier_scorecards`
  ADD CONSTRAINT `fk_supplier_scorecards_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`);

--
-- Constraints for table `user_activity_snapshots`
--
ALTER TABLE `user_activity_snapshots`
  ADD CONSTRAINT `fk_activity_snapshot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `what_if_scenarios`
--
ALTER TABLE `what_if_scenarios`
  ADD CONSTRAINT `fk_what_if_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  ADD CONSTRAINT `fk_what_if_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `fk_what_if_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `workflow_cancellations`
--
ALTER TABLE `workflow_cancellations`
  ADD CONSTRAINT `fk_workflow_cancel_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_workflow_cancel_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- --------------------------------------------------------
-- Bulk import staging rows (added by XLSX import upgrade)
-- --------------------------------------------------------
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

-- METRICCON FLOW ACCOUNTABILITY UPGRADE (integrated for fresh installs)
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


-- ============================================================================
-- CLEAN MASTER / REQUIRED STARTER DATA
-- Transaction tables intentionally remain EMPTY.
-- Test password for all starter accounts: Metriccon@123
-- IMPORTANT: Change these passwords before production use.
-- ============================================================================
START TRANSACTION;

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('adjustment_prefix', 'ADJ'),
('auto_backup_enabled', '1'),
('auto_backup_time', '22:00'),
('backup_prefix', 'BACKUP'),
('backup_retention_days', '14'),
('company_name', 'Metriccon Co., Inc.'),
('critical_stock_threshold_percent', '25'),
('damage_prefix', 'DMG'),
('default_table_page_size', '15'),
('default_warehouse_id', '1'),
('delivery_prefix', 'DEL'),
('forecast_arima_order', '1,1,1'),
('forecast_daily_enabled', '1'),
('forecast_daily_time', '06:00'),
('forecast_history_days', '180'),
('forecast_horizon_days', '30'),
('forecast_python_path', 'python'),
('forecast_script_path', 'scripts/forecast_arima.py'),
('forecasting_phase', 'reserved_for_next_phase'),
('forecasting_status', 'ready'),
('gemini_enabled', '1'),
('gemini_model', 'gemini-2.0-flash'),
('location_code_prefix', 'LOC'),
('material_code_prefix', 'MAT'),
('movement_prefix', 'MOV'),
('password_min_length', '8'),
('password_require_complexity', '1'),
('purchase_order_prefix', 'PO'),
('purchase_request_prefix', 'PR'),
('pwa_offline_enabled', '1'),
('release_prefix', 'REL'),
('reorder_alert_days', '7'),
('report_footer_text', 'Metriccon Inventory Control Hub'),
('request_prefix', 'MR'),
('return_prefix', 'RET'),
('session_timeout_minutes', '45'),
('site_code_prefix', 'SITE'),
('stock_count_prefix', 'SC'),
('transfer_prefix', 'TR'),
('two_factor_optional', '1'),
('unit_code_prefix', 'UOM');

INSERT INTO `material_categories` (`id`, `name`, `is_active`) VALUES
(1, 'Cement', 1),
(2, 'Steel', 1),
(3, 'Aggregates', 1),
(4, 'Electrical', 1),
(5, 'Plumbing', 1),
(6, 'Lumber', 1),
(7, 'Masonry', 1),
(8, 'Hardware', 1);

INSERT INTO `units` (`id`, `code`, `name`, `is_active`) VALUES
(1, 'UOM-001', 'Bag', 1),
(2, 'UOM-002', 'Piece', 1),
(3, 'UOM-003', 'Meter', 1),
(4, 'UOM-004', 'Cubic Meter', 1),
(5, 'UOM-005', 'Kilogram', 1);

INSERT INTO `sites` (`id`, `code`, `name`, `address`, `status`, `remarks`) VALUES
(1, 'SITE-0001', 'Warehouse Operations', 'Main warehouse operations', 'active', 'Warehouse master record'),
(2, 'SITE-0002', 'Test Project A', 'Project Site A', 'active', 'Starter project for flow testing'),
(3, 'SITE-0003', 'Test Project B', 'Project Site B', 'active', 'Starter project for site transfer testing');

INSERT INTO `locations` (`id`, `site_id`, `code`, `name`, `type`, `is_default`, `is_active`) VALUES
(1, 1, 'LOC-0001', 'Central Warehouse', 'warehouse', 1, 1),
(2, 2, 'LOC-0002', 'Project A Stockroom', 'site', 0, 1),
(3, 3, 'LOC-0003', 'Project B Stockroom', 'site', 0, 1),
(4, 1, 'LOC-0004', 'Secondary Warehouse', 'warehouse', 0, 1);

INSERT INTO `suppliers` (`id`, `company`, `contact_person`, `phone`, `address`, `is_active`) VALUES
(1, 'MetroBuild Supplies', 'Sample Contact A', '09000000001', 'Metro Manila', 1),
(2, 'Prime Construction Supply', 'Sample Contact B', '09000000002', 'Metro Manila', 1);

INSERT INTO `users` (`id`, `username`, `full_name`, `email`, `password_hash`, `role`, `site_id`, `department_id`, `is_active`, `two_factor_enabled`) VALUES
(1, 'admin', 'IT Administrator', 'admin@metriccon.local', '$2y$12$0rdaziar8Kn34EDmfd.QQ.9uyhZUnmeejTmWKYeRHQ44zhm0BYVIi', 'admin', NULL, NULL, 1, 0),
(2, 'manager', 'General Warehouse Manager', 'manager@metriccon.local', '$2y$12$0rdaziar8Kn34EDmfd.QQ.9uyhZUnmeejTmWKYeRHQ44zhm0BYVIi', 'warehouse_manager', NULL, NULL, 1, 0),
(3, 'engineer_a', 'Site Engineer A', 'engineer.a@metriccon.local', '$2y$12$0rdaziar8Kn34EDmfd.QQ.9uyhZUnmeejTmWKYeRHQ44zhm0BYVIi', 'site_engineer', 2, NULL, 1, 0),
(4, 'warehouse', 'Warehouse Personnel', 'warehouse@metriccon.local', '$2y$12$0rdaziar8Kn34EDmfd.QQ.9uyhZUnmeejTmWKYeRHQ44zhm0BYVIi', 'warehouse_personnel', NULL, NULL, 1, 0),
(5, 'procurement', 'Procurement Officer', 'procurement@metriccon.local', '$2y$12$0rdaziar8Kn34EDmfd.QQ.9uyhZUnmeejTmWKYeRHQ44zhm0BYVIi', 'procurement', NULL, NULL, 1, 0),
(6, 'president', 'President / Executive', 'president@metriccon.local', '$2y$12$0rdaziar8Kn34EDmfd.QQ.9uyhZUnmeejTmWKYeRHQ44zhm0BYVIi', 'president', NULL, NULL, 1, 0),
(7, 'engineer_b', 'Site Engineer B', 'engineer.b@metriccon.local', '$2y$12$0rdaziar8Kn34EDmfd.QQ.9uyhZUnmeejTmWKYeRHQ44zhm0BYVIi', 'site_engineer', 3, NULL, 1, 0);

INSERT INTO `materials`
(`id`, `material_code`, `material_name`, `description`, `category_id`, `unit_id`, `brand_spec`, `min_stock`, `max_stock`, `reorder_point`, `safety_stock`, `lead_time_days`, `standard_cost`, `is_critical`, `track_lot`, `track_expiry`, `shelf_life_days`, `barcode_value`, `preferred_supplier_id`, `is_active`, `approval_status`)
VALUES
(1, 'MAT-000001', 'Portland Cement', 'General-purpose construction cement', 1, 1, 'Type 1, 40 kg', 50, 500, 100, 25, 5, 285, 1, 0, 0, 0, NULL, 1, 1, 'approved'),
(2, 'MAT-000002', '10mm Reinforcing Bar', 'Deformed steel reinforcing bar', 2, 2, '10 mm x 6 m', 100, 1000, 200, 50, 7, 210, 1, 0, 0, 0, NULL, 1, 1, 'approved'),
(3, 'MAT-000003', 'Washed Sand', 'Washed construction sand', 3, 4, 'Clean fine aggregate', 15, 150, 30, 10, 3, 1250, 0, 0, 0, 0, NULL, 2, 1, 'approved'),
(4, 'MAT-000004', 'THHN Wire 2.0mm', 'Copper electrical building wire', 4, 3, '2.0 sq.mm', 100, 1000, 200, 50, 5, 18.50, 1, 0, 0, 0, NULL, 2, 1, 'approved'),
(5, 'MAT-000005', 'PVC Pipe 1/2 Inch', 'PVC waterline pipe', 5, 2, '1/2 inch x 3 m', 20, 200, 40, 10, 6, 115, 1, 0, 0, 0, NULL, 1, 1, 'approved'),
(6, 'MAT-000006', 'Plywood 1/2 Inch', 'Construction-grade plywood', 6, 2, '1/2 inch, 4 x 8 ft', 20, 200, 40, 10, 5, 780, 0, 0, 0, 0, NULL, 2, 1, 'approved');

-- Keep master-code generators above the seeded master IDs. Document sequences remain empty
-- so the first MR/PR/PO/etc. generated in this clean database starts at 0001.
INSERT INTO `number_sequences` (`sequence_key`, `scope_key`, `last_value`) VALUES
('master.material', 'global', 6),
('master.site', 'global', 3),
('master.location', 'global', 4),
('master.unit', 'global', 5);

COMMIT;
