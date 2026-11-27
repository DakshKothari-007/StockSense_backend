-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 27, 2026 at 10:06 AM
-- Server version: 10.4.24-MariaDB
-- PHP Version: 8.1.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `stocksense`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_cache`
--

CREATE TABLE `ai_cache` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_id` int(10) UNSIGNED NOT NULL,
  `cache_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cache_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'chat',
  `response_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_usage`
--

CREATE TABLE `ai_usage` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_id` int(10) UNSIGNED NOT NULL,
  `usage_date` date NOT NULL,
  `request_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `last_request_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bundle_offers`
--

CREATE TABLE `bundle_offers` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `product_id_1` int(10) UNSIGNED NOT NULL,
  `product_id_2` int(10) UNSIGNED NOT NULL,
  `offer_price` decimal(10,2) NOT NULL,
  `combined_price` decimal(10,2) NOT NULL,
  `status` enum('active','ended') NOT NULL DEFAULT 'active',
  `active_lock` tinyint(1) GENERATED ALWAYS AS (if(`status` = 'active',1,NULL)) VIRTUAL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `device_tokens`
--

CREATE TABLE `device_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fcm_token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'android',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_seen_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `digest_log`
--

CREATE TABLE `digest_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(11) NOT NULL,
  `sent_at` datetime NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `import_history`
--

CREATE TABLE `import_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `success_count` int(11) NOT NULL DEFAULT 0,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('completed','failed','partial') NOT NULL DEFAULT 'completed',
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `import_mappings`
--

CREATE TABLE `import_mappings` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `headers_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_headers` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`mapping`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `intent_misses`
--

CREATE TABLE `intent_misses` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_text` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_text` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chosen_intent` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `confidence` decimal(4,3) NOT NULL,
  `layer_resolved` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'layer_b',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL,
  `identifier` varchar(191) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `notification_states`
--

CREATE TABLE `notification_states` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `notification_key` varchar(100) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `dismissed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `owner_account`
--

CREATE TABLE `owner_account` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `shop_name` varchar(150) NOT NULL,
  `gstin` varchar(15) DEFAULT NULL,
  `avatar_style` varchar(20) DEFAULT NULL,
  `avatar_seed` varchar(64) DEFAULT NULL,
  `avatar_options` text DEFAULT NULL,
  `location` varchar(255) NOT NULL DEFAULT 'Indiranagar, Bengaluru',
  `store_type` varchar(100) NOT NULL DEFAULT 'Neighbourhood supermarket',
  `currency` varchar(50) NOT NULL DEFAULT '₹ INR / IST',
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `description` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `units` int(11) NOT NULL DEFAULT 0,
  `min_stock_threshold` int(11) NOT NULL DEFAULT 10,
  `status` varchar(100) DEFAULT NULL,
  `icon_type` tinyint(4) NOT NULL DEFAULT 0,
  `icon_key` varchar(100) DEFAULT NULL,
  `icon_is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `order_no` varchar(50) NOT NULL,
  `status` enum('placed','received','cancelled') NOT NULL DEFAULT 'placed',
  `total_estimated_cost` decimal(10,2) DEFAULT NULL,
  `total_items` int(11) NOT NULL DEFAULT 0,
  `total_units` int(11) NOT NULL DEFAULT 0,
  `received_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `purchase_order_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `line_estimated_cost` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `push_log`
--

CREATE TABLE `push_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_id` int(10) UNSIGNED NOT NULL,
  `dedupe_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(10) UNSIGNED NOT NULL,
  `action` varchar(50) NOT NULL,
  `identifier` varchar(191) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `recommendation_responses`
--

CREATE TABLE `recommendation_responses` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `recommendation_type` enum('slow_moving','product_pair','reorder') NOT NULL,
  `product_id_1` int(10) UNSIGNED NOT NULL,
  `product_id_2` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `action` enum('accepted','dismissed') NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(10) UNSIGNED NOT NULL,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `bill_no` varchar(50) NOT NULL,
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `sales_items`
--

CREATE TABLE `sales_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `sale_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `tokens`
--

CREATE TABLE `tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `low_stock_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `ai_recommendations` tinyint(1) NOT NULL DEFAULT 1,
  `daily_sales_summary` tinyint(1) NOT NULL DEFAULT 1,
  `weekly_email_digest` tinyint(1) NOT NULL DEFAULT 0,
  `theme_mode` varchar(20) NOT NULL DEFAULT 'SYSTEM',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lead_time_days` int(10) UNSIGNED NOT NULL DEFAULT 2,
  `safety_days` int(10) UNSIGNED NOT NULL DEFAULT 3,
  `cover_days` int(10) UNSIGNED NOT NULL DEFAULT 7,
  `min_history_days` int(10) UNSIGNED NOT NULL DEFAULT 30,
  `slow_moving_threshold_days` int(10) UNSIGNED NOT NULL DEFAULT 60,
  `import_mode` enum('ADD','REPLACE') NOT NULL DEFAULT 'ADD',
  `timezone` varchar(50) NOT NULL DEFAULT 'Asia/Kolkata',
  `failed_password_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `password_locked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_cache`
--
ALTER TABLE `ai_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shop_cache` (`shop_id`,`cache_key`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `ai_usage`
--
ALTER TABLE `ai_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shop_day` (`shop_id`,`usage_date`),
  ADD KEY `idx_shop_last_req` (`shop_id`,`last_request_at`);

--
-- Indexes for table `bundle_offers`
--
ALTER TABLE `bundle_offers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_active_bundle_offer` (`shop_owner_id`,`product_id_1`,`product_id_2`,`active_lock`),
  ADD KEY `idx_bundle_offers_shop_status` (`shop_owner_id`,`status`),
  ADD KEY `idx_bundle_offers_p1` (`product_id_1`),
  ADD KEY `idx_bundle_offers_p2` (`product_id_2`);

--
-- Indexes for table `device_tokens`
--
ALTER TABLE `device_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fcm_token` (`fcm_token`),
  ADD KEY `idx_shop_id` (`shop_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `digest_log`
--
ALTER TABLE `digest_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner_sent` (`shop_owner_id`,`sent_at`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `import_history`
--
ALTER TABLE `import_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_import_owner` (`shop_owner_id`),
  ADD KEY `idx_owner_idempotency` (`shop_owner_id`,`idempotency_key`);

--
-- Indexes for table `import_mappings`
--
ALTER TABLE `import_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_owner_headers` (`shop_owner_id`,`headers_hash`),
  ADD KEY `idx_owner` (`shop_owner_id`);

--
-- Indexes for table `intent_misses`
--
ALTER TABLE `intent_misses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_intent_conf` (`chosen_intent`,`confidence`),
  ADD KEY `idx_normalized_text` (`normalized_text`(191)),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_identifier_time` (`identifier`,`attempted_at`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempted_at`);

--
-- Indexes for table `notification_states`
--
ALTER TABLE `notification_states`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_owner_notif` (`shop_owner_id`,`notification_key`),
  ADD KEY `idx_owner_read` (`shop_owner_id`,`read_at`);

--
-- Indexes for table `owner_account`
--
ALTER TABLE `owner_account`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shop_sku` (`shop_owner_id`,`sku`),
  ADD KEY `idx_shop_owner` (`shop_owner_id`),
  ADD KEY `idx_shop_units` (`shop_owner_id`,`units`),
  ADD KEY `idx_products_icon_key` (`icon_key`),
  ADD KEY `idx_products_icon_manual` (`icon_is_manual`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shop_order` (`shop_owner_id`,`order_no`),
  ADD KEY `idx_orders_owner_status` (`shop_owner_id`,`status`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_poi_order` (`purchase_order_id`),
  ADD KEY `idx_poi_product` (`product_id`);

--
-- Indexes for table `push_log`
--
ALTER TABLE `push_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shop_dedupe` (`shop_id`,`dedupe_key`),
  ADD KEY `idx_shop_id` (`shop_id`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_action_id_time` (`action`,`identifier`,`attempted_at`),
  ADD KEY `idx_action_ip_time` (`action`,`ip_address`,`attempted_at`);

--
-- Indexes for table `recommendation_responses`
--
ALTER TABLE `recommendation_responses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_owner_rec` (`shop_owner_id`,`recommendation_type`,`product_id_1`,`product_id_2`),
  ADD KEY `idx_rec_owner_type` (`shop_owner_id`,`recommendation_type`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shop_bill` (`shop_owner_id`,`bill_no`),
  ADD KEY `idx_sales_owner_date` (`shop_owner_id`,`sale_date`);

--
-- Indexes for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sales_items_sale` (`sale_id`),
  ADD KEY `idx_sales_items_product` (`product_id`),
  ADD KEY `idx_sale_product` (`sale_id`,`product_id`);

--
-- Indexes for table `tokens`
--
ALTER TABLE `tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_token_user_id` (`user_id`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_cache`
--
ALTER TABLE `ai_cache`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_usage`
--
ALTER TABLE `ai_usage`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bundle_offers`
--
ALTER TABLE `bundle_offers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `device_tokens`
--
ALTER TABLE `device_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `digest_log`
--
ALTER TABLE `digest_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_history`
--
ALTER TABLE `import_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_mappings`
--
ALTER TABLE `import_mappings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `intent_misses`
--
ALTER TABLE `intent_misses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_states`
--
ALTER TABLE `notification_states`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `owner_account`
--
ALTER TABLE `owner_account`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `push_log`
--
ALTER TABLE `push_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recommendation_responses`
--
ALTER TABLE `recommendation_responses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_items`
--
ALTER TABLE `sales_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tokens`
--
ALTER TABLE `tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_settings`
--
ALTER TABLE `user_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bundle_offers`
--
ALTER TABLE `bundle_offers`
  ADD CONSTRAINT `fk_bundle_offers_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bundle_offers_p1` FOREIGN KEY (`product_id_1`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bundle_offers_p2` FOREIGN KEY (`product_id_2`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `import_history`
--
ALTER TABLE `import_history`
  ADD CONSTRAINT `fk_import_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_states`
--
ALTER TABLE `notification_states`
  ADD CONSTRAINT `fk_notif_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_poi_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_poi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `recommendation_responses`
--
ALTER TABLE `recommendation_responses`
  ADD CONSTRAINT `fk_rec_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sales_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD CONSTRAINT `fk_sales_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_sales_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tokens`
--
ALTER TABLE `tokens`
  ADD CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `fk_user_settings_owner` FOREIGN KEY (`user_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
