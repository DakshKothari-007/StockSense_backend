-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 31, 2026 at 10:19 AM
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

--
-- Dumping data for table `import_history`
--

INSERT INTO `import_history` (`id`, `shop_owner_id`, `idempotency_key`, `file_name`, `file_size`, `total_rows`, `success_count`, `failed_count`, `status`, `details`, `created_at`) VALUES
(1, 2, NULL, 'sample_error_import.csv', 207, 5, 0, 2, 'failed', '{\"errors\":[{\"row_number\":4,\"field\":\"units\",\"reason\":\"Invalid quantity: \'18O\'\"},{\"row_number\":6,\"field\":\"sku\",\"reason\":\"Invalid SKU\"}]}', '2026-10-28 05:14:58'),
(2, 2, NULL, 'sample_error_import.csv', 207, 5, 0, 2, 'failed', '{\"errors\":[{\"row_number\":4,\"field\":\"units\",\"reason\":\"Invalid quantity: \'18O\'\"},{\"row_number\":6,\"field\":\"sku\",\"reason\":\"Invalid SKU\"}]}', '2026-10-28 05:16:30'),
(3, 2, NULL, 'sample_valid_import.csv', 213, 5, 5, 0, 'completed', '{\"products_updated\":4,\"products_added\":1,\"stock_added\":134,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":7,\"new_inventory_value\":27070}', '2026-10-28 05:16:30'),
(4, 2, NULL, 'sample_error_import.csv', 207, 5, 0, 2, 'failed', '{\"errors\":[{\"row_number\":4,\"field\":\"units\",\"reason\":\"Invalid quantity: \'18O\'\"},{\"row_number\":6,\"field\":\"sku\",\"reason\":\"Invalid SKU\"}]}', '2026-10-28 05:20:51'),
(5, 2, NULL, 'sample_valid_import.csv', 213, 5, 5, 0, 'completed', '{\"products_updated\":5,\"products_added\":0,\"stock_added\":134,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":7,\"new_inventory_value\":35690}', '2026-10-28 05:20:52'),
(6, 2, NULL, 'sample_error_import.csv', 207, 5, 0, 2, 'failed', '{\"errors\":[{\"row_number\":4,\"field\":\"units\",\"reason\":\"Invalid quantity: \'18O\'\"},{\"row_number\":6,\"field\":\"sku\",\"reason\":\"Invalid SKU\"}]}', '2026-10-28 05:21:23'),
(7, 2, NULL, 'sample_valid_import.csv', 213, 5, 5, 0, 'completed', '{\"products_updated\":4,\"products_added\":1,\"stock_added\":134,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":7,\"new_inventory_value\":41710}', '2026-10-28 05:21:23'),
(8, 6, NULL, 'stock_oct15.xlsx', 213, 5, 5, 0, 'completed', '{\"products_updated\":0,\"products_added\":5,\"stock_added\":134,\"corrected_rows\":1,\"skipped_rows\":0,\"total_active_products\":6,\"new_inventory_value\":15870}', '2026-10-28 06:39:13'),
(9, 2, NULL, 'sales_import_20261028_135348', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-28 08:23:48'),
(10, 2, NULL, 'sales_import_20261028_135348', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-28 08:23:48'),
(11, 2, NULL, 'sales_import_20261028_140104', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-28 08:31:04'),
(12, 2, NULL, 'sales_import_20261028_140104', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-28 08:31:04'),
(13, 2, NULL, 'sales_import_20261028_141738', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-28 08:47:38'),
(14, 2, NULL, 'sales_import_20261028_141738', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-28 08:47:38'),
(15, 2, NULL, 'sales_import_20261028_145446', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-28 09:24:46'),
(16, 2, NULL, 'sales_import_20261028_145446', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-28 09:24:46'),
(17, 2, NULL, 'sales_import_20261029_085000', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 03:20:00'),
(18, 2, NULL, 'sales_import_20261029_085000', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 03:20:00'),
(19, 2, NULL, 'sales_import_20261029_085558', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 03:25:58'),
(20, 2, NULL, 'sales_import_20261029_085558', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 03:25:58'),
(21, 2, NULL, 'sales_import_20261029_085617', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 03:26:17'),
(22, 2, NULL, 'sales_import_20261029_085617', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 03:26:17'),
(23, 2, NULL, 'sales_import_20261029_090857', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 03:38:57'),
(24, 2, NULL, 'sales_import_20261029_090858', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 03:38:58'),
(25, 2, NULL, 'sales_import_20261029_091516', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 03:45:16'),
(26, 2, NULL, 'sales_import_20261029_091516', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 03:45:16'),
(27, 2, NULL, 'sales_import_20261029_091542', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 03:45:42'),
(28, 2, NULL, 'sales_import_20261029_091542', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 03:45:42'),
(29, 2, NULL, 'sales_import_20261029_092736', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 03:57:36'),
(30, 2, NULL, 'sales_import_20261029_092736', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 03:57:36'),
(31, 2, NULL, 'test_notif.csv', 1024, 25, 20, 5, 'completed', NULL, '2026-10-29 04:36:10'),
(32, 2, NULL, 'test_notif.csv', 1024, 25, 20, 5, 'completed', NULL, '2026-10-29 04:40:03'),
(34, 2, NULL, 'sales_import_20261029_101805', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 04:48:05'),
(35, 2, NULL, 'sales_import_20261029_101805', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 04:48:05'),
(36, 2, NULL, 'test_notif.csv', 1024, 25, 20, 5, 'completed', NULL, '2026-10-29 04:49:06'),
(38, 2, NULL, 'test_notif.csv', 1024, 25, 20, 5, 'completed', NULL, '2026-10-29 05:01:27'),
(40, 2, NULL, 'test_notif.csv', 1024, 25, 20, 5, 'completed', NULL, '2026-10-29 09:19:46'),
(42, 2, NULL, 'sales_import_20261029_145034', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-29 09:20:34'),
(43, 2, NULL, 'sales_import_20261029_145034', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-29 09:20:34'),
(44, 2, NULL, 'test_notif.csv', 1024, 25, 20, 5, 'completed', NULL, '2026-10-30 02:52:09'),
(46, 2, NULL, 'sales_import_20261030_082226', 121, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'NON-EXISTENT-SKU\' does not exist in your store inventory.\"}]}', '2026-10-30 02:52:26'),
(47, 2, NULL, 'sales_import_20261030_082226', 128, 1, 1, 0, 'completed', '{\"bills_created\":1,\"items_created\":1,\"total_sales_amount\":150,\"deduct_stock\":false}', '2026-10-30 02:52:26'),
(48, 23, NULL, 'sales_import_20261030_142815', 307, 2, 0, 1, 'failed', '{\"errors\":[{\"row\":2,\"field\":\"sku\",\"reason\":\"Product \'STP-003\' does not exist in your store inventory.\"}]}', '2026-10-30 08:58:15'),
(49, 23, NULL, '1_good_import.csv', 289, 5, 5, 0, 'completed', '{\"products_updated\":0,\"products_added\":5,\"stock_added\":224,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":8,\"new_inventory_value\":18405}', '2026-10-31 05:27:01'),
(50, 2, NULL, '1_good_import.csv', 230, 2, 2, 0, 'completed', '{\"products_updated\":0,\"products_added\":2,\"stock_added\":75,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":15,\"new_inventory_value\":84700}', '2026-10-31 06:54:04'),
(51, 2, NULL, '1_good_import.csv', 230, 2, 2, 0, 'completed', '{\"products_updated\":0,\"products_added\":2,\"stock_added\":75,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":15,\"new_inventory_value\":84700}', '2026-10-31 06:56:01'),
(52, 2, NULL, '1_good_import.csv', 230, 2, 2, 0, 'completed', '{\"products_updated\":0,\"products_added\":2,\"stock_added\":75,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":15,\"new_inventory_value\":84700}', '2026-10-31 06:57:45'),
(53, 23, NULL, 'A_no_sku_pos_style.csv', 109, 3, 3, 0, 'completed', '{\"products_updated\":0,\"products_added\":3,\"stock_added\":115,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":10,\"new_inventory_value\":30660}', '2026-10-31 08:28:53'),
(54, 23, NULL, '1_good_import.csv', 289, 5, 5, 0, 'completed', '{\"products_updated\":0,\"products_added\":5,\"stock_added\":224,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":11,\"new_inventory_value\":34966}', '2026-10-31 08:32:05'),
(55, 23, NULL, 'B_barcode_style.csv', 157, 3, 3, 0, 'completed', '{\"products_updated\":0,\"products_added\":3,\"stock_added\":262,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":14,\"new_inventory_value\":40662}', '2026-10-31 08:33:28'),
(56, 23, NULL, 'C_extra_columns.csv', 238, 2, 2, 0, 'completed', '{\"products_updated\":0,\"products_added\":2,\"stock_added\":65,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":16,\"new_inventory_value\":46277}', '2026-10-31 08:35:18'),
(57, 23, NULL, 'D_name_and_description.csv', 158, 2, 2, 0, 'completed', '{\"products_updated\":0,\"products_added\":2,\"stock_added\":118,\"corrected_rows\":0,\"skipped_rows\":0,\"total_active_products\":18,\"new_inventory_value\":49117}', '2026-10-31 08:37:42'),
(58, 23, NULL, '2_bad_row.csv', 207, 3, 3, 0, 'completed', '{\"products_updated\":0,\"products_added\":3,\"stock_added\":36,\"corrected_rows\":1,\"skipped_rows\":0,\"total_active_products\":21,\"new_inventory_value\":53514}', '2026-10-31 08:40:38');

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

--
-- Dumping data for table `import_mappings`
--

INSERT INTO `import_mappings` (`id`, `shop_owner_id`, `headers_hash`, `raw_headers`, `mapping`, `created_at`, `updated_at`) VALUES
(1, 9999, '27aaf2ca407382abbfe5957f7863e3b6000dbcac3b2c45b38dad0b5ffdb7cfef', '[\"Col A\",\"Col B\",\"Col C\"]', '{\"name\":\"Col A\",\"units\":\"Col B\",\"price\":\"Col C\"}', '2026-10-31 07:45:58', '2026-10-31 09:02:45'),
(2, 23, '4cfa104043a96e0b99ad97643797343e5b5aaebfb1c2e2e9611e69490d6c0100', '[\"Item Name\",\"Qty\",\"MRP\",\"Purchase Rate\"]', '{\"name\":\"Item Name\",\"units\":\"Qty\",\"cost_price\":\"Purchase Rate\",\"price\":\"MRP\"}', '2026-10-31 08:28:53', '2026-10-31 08:28:53'),
(3, 23, 'b0392d6e689fdc89450b465d5290f2b4a65e739aaa9ba28888fd8f882c4f04af', '[\"Product Name\",\"SKU\",\"Category\",\"Quantity\",\"Cost Price\",\"Selling Price\",\"Minimum Stock\"]', '{\"name\":\"Product Name\",\"sku\":\"SKU\",\"units\":\"Quantity\",\"cost_price\":\"Cost Price\",\"price\":\"Selling Price\",\"category\":\"Category\",\"minimum_stock\":\"Minimum Stock\"}', '2026-10-31 08:32:06', '2026-10-31 08:40:38'),
(4, 23, '5c90a6f112aca2438aaca1c31a90b41faaa7d7305678421574cd476f0280faff', '[\"Description\",\"Barcode\",\"Qty On Hand\",\"Rate\"]', '{\"name\":\"Description\",\"sku\":\"Barcode\",\"units\":\"Qty On Hand\",\"price\":\"Rate\"}', '2026-10-31 08:33:28', '2026-10-31 08:33:28'),
(5, 23, '3fd0c5767f7e8f2917e40a9d4ab1f66b6fff9c39b0ab7450ba2c5d8ceb236b44', '[\"Product\",\"Article No\",\"Units\",\"Unit Cost\",\"Selling Price\",\"Group\",\"Supplier\",\"Tax %\",\"Shelf Location\",\"Expiry Date\"]', '{\"name\":\"Product\",\"sku\":\"Article No\",\"units\":\"Units\",\"cost_price\":\"Unit Cost\",\"price\":\"Selling Price\",\"category\":\"Group\"}', '2026-10-31 08:35:18', '2026-10-31 08:35:18'),
(6, 23, '7aab39e561a9c2eef76e39bac75fec6a20da90016bc0203541d9ba7fdb24b667', '[\"Product Name\",\"Description\",\"Qty\",\"Selling Price\",\"Cost Price\"]', '{\"name\":\"Product Name\",\"units\":\"Qty\",\"cost_price\":\"Cost Price\",\"price\":\"Selling Price\"}', '2026-10-31 08:37:42', '2026-10-31 08:37:42'),
(8, 2, 'efaa4beee3ce838e5ab75a7b2afd13a760aee18df9fac90813057ec20fd53c6a', '[\"Product Name\",\"SKU\",\"Quantity\",\"Cost Price\",\"Selling Price\",\"Category\"]', '{\"name\":\"Product Name\",\"sku\":\"SKU\",\"units\":\"Quantity\",\"cost_price\":\"Cost Price\",\"price\":\"Selling Price\",\"category\":\"Category\"}', '2026-10-31 09:02:38', '2026-10-31 09:02:38');

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

--
-- Dumping data for table `notification_states`
--

INSERT INTO `notification_states` (`id`, `shop_owner_id`, `notification_key`, `read_at`, `dismissed_at`, `created_at`) VALUES
(71, 2, 'low_stock:117', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(72, 2, 'low_stock:118', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(73, 2, 'low_stock:4', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(74, 2, 'slow_moving:1', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(75, 2, 'slow_moving:114', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(76, 2, 'slow_moving:115', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(77, 2, 'slow_moving:120', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(78, 2, 'slow_moving:2', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(79, 2, 'slow_moving:3', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(80, 2, 'slow_moving:5', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(81, 2, 'slow_moving:7', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(82, 2, 'slow_moving:9', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10'),
(83, 2, 'import:44', '2026-10-30 08:22:10', NULL, '2026-10-30 08:22:10');

-- --------------------------------------------------------

--
-- Table structure for table `owner_account`
--

CREATE TABLE `owner_account` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `shop_name` varchar(150) NOT NULL,
  `location` varchar(255) NOT NULL DEFAULT 'Indiranagar, Bengaluru',
  `store_type` varchar(100) NOT NULL DEFAULT 'Neighbourhood supermarket',
  `currency` varchar(50) NOT NULL DEFAULT '₹ INR / IST',
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `owner_account`
--

INSERT INTO `owner_account` (`id`, `full_name`, `shop_name`, `location`, `store_type`, `currency`, `email`, `password`, `created_at`, `updated_at`) VALUES
(2, 'Ananya Rao', 'Fresh Mart Express', 'Indiranagar 100ft Rd, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'ananya@example.com', '$2y$10$N3ecdEHxKMoSJZ9rR32B8./j2szPAQlOVikSDdJ1/pJ0L1cA8OCJm', '2026-10-26 04:39:32', '2026-10-30 02:52:34'),
(3, 'daksh', 'Fresh Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'daksh1@gmail.com', '$2y$10$dTHlmwKWcCmEzWSUaPyJKeEZ/TpS86JQUVYTjqX7yiVQswL1G9am.', '2026-10-26 04:43:23', '2026-10-30 02:52:08'),
(4, 'Isha', 'Jio Supermart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'kotharidaksh@gmail.com', '$2y$10$0tpQXI7/g/T6Jtoj9MvC8egiMBeqv.ZDLxPg039s6gU0ftlx0kG2O', '2026-10-27 05:11:01', '2026-10-27 08:08:40'),
(5, 'Demo Store Owner', 'Demo Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'testuser_1793089009@example.com', '$2y$10$fAmtOss0zhcG8fnWz2mG4eZU3UaSX15LHapZoHGdkZHnoiggJc5j2', '2026-10-27 08:16:49', '2026-10-27 08:16:49'),
(6, 'Ananya Rao', 'SPCC Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'ananya@greenbasket.i', '$2y$10$6nXurYfe.DB/3bVWamNiXurYucfvXfFaW9u8s3.A6tBvUzGp2vMXu', '2026-10-28 04:52:23', '2026-10-29 09:11:22'),
(7, 'Amit V. Kappor', 'Kappor Supermarket', 'MVN, Chennai', 'Neighbourhood supermarket', '₹ INR / IST', 'amit.kappor.new@gmail.com', '$2y$10$MVlNwD5619kqnos0P63GPe6Oo.gdNyPirptoGQjcRB8Jhqb.dAtpa', '2026-10-29 02:49:14', '2026-10-29 07:57:05'),
(8, 'Disposable User', 'Disposable Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'delete_me_1793248570@example.com', '$2y$10$wde5enkGyPfJtc3ex.wusuuYJQ6oTeW3l1uz9Mg/bSjBBj4VKdMW2', '2026-10-29 04:36:10', '2026-10-29 04:36:10'),
(12, 'DAKSH KOTHARI', 'BAKERY MART', 'TRL , CHENNAI', 'Grocery store', 'INR (₹)', 'daksh222@gmail.com', '$2y$10$oyifOpC7B32fpc18neBf7eqmThSATkfnEUeIAz8cHCF05i1QxAnG6', '2026-10-29 08:30:03', '2026-10-29 08:30:03'),
(13, 'Amit Kappor', 'SPCC MART', 'MVN, Chennai', 'Neighbourhood supermarket', '₹ INR / IST', 'amitkappor@gmail.com', '$2y$10$fa6hyt287QbWuFL.6zohy.MPzBG2OHKYcadJZ3/2BFoyDVx75OSXa', '2026-10-29 08:34:02', '2026-10-29 08:34:02'),
(19, 'Rohan K. Sharma', 'Sharma Mega Mart', 'Anna Nagar, Chennai', 'Medium supermarket', '₹ INR / IST', 'rohan.updated@gmail.com', '$2y$10$FPv8w5E69CXb0U1T6qPKd.F1naBt0oaDXsdqI9o/WqEkIzFGLFZYa', '2026-10-30 02:59:27', '2026-10-30 02:59:27'),
(23, 'Thaurn', 'Lulu Market', 'Shenoy Nagar, Chennai', 'Neighbourhood supermarket', 'INR (₹)', 'thaurn@gmail.com', '$2y$10$wpwmSYlflQxPz4xRZPr0pOWd9UnqIwjs/dgKYvsOSflw4eN2xCxWW', '2026-10-30 04:38:22', '2026-10-30 04:40:20'),
(24, 'Test Owner', 'Test Shop', 'Chennai', 'Medium supermarket', '₹', 'owner@test.com', '$2y$10$bZngM6aGPOMfDpi/DnM3gu8zzk32Dz0kBuR6sn5FE9pA6JBunkww6', '2026-10-31 06:50:40', '2026-10-31 06:50:40');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `shop_owner_id`, `name`, `sku`, `category`, `description`, `price`, `cost_price`, `units`, `min_stock_threshold`, `status`, `icon_type`, `created_at`, `updated_at`) VALUES
(1, 2, 'Amul Milk - 500 ml', 'DAI-001', 'Dairy', '500 ml · Fresh daily', '50.00', '27.00', 90, 24, 'Active', 0, '2026-10-27 09:04:44', '2026-10-30 02:52:25'),
(2, 2, 'Britannia Marie · 150 g', 'SNK-014', 'Snacks', '150 g · Tea-time biscuits', '30.00', '25.00', 96, 30, 'Slow moving · 6 sold in 30 days', 1, '2026-10-27 09:04:44', '2026-10-27 09:04:44'),
(3, 2, 'Aashirvaad Atta - 5 kg', 'STP-003', 'Staples', '5 kg · Whole wheat atta', '260.00', '235.00', 60, 15, NULL, 2, '2026-10-27 09:04:44', '2026-10-28 05:21:23'),
(4, 2, 'Tata Tea - 250 g', 'BEV-008', 'Beverages', '250 g · Premium leaf tea', '120.00', '0.00', 0, 20, 'Active', 3, '2026-10-27 09:04:44', '2026-10-28 08:47:14'),
(5, 2, 'Parle-G - 80 g', 'SNK-002', 'Snacks', '80 g · Glucose biscuits', '10.00', '8.00', 192, 40, NULL, 1, '2026-10-27 09:04:44', '2026-10-28 05:21:23'),
(7, 2, 'Colgate Total · 150 g', 'PER-651', 'Personal care', 'Imported product batch', '95.00', '78.00', 30, 10, NULL, 2, '2026-10-27 09:09:31', '2026-10-27 09:09:31'),
(9, 2, 'Surf Excel - 500 g', 'HOM-021', 'General', 'Imported product', '65.00', '58.00', 20, 10, NULL, 0, '2026-10-28 05:21:23', '2026-10-28 05:21:23'),
(10, 6, 'Fortune Sunflower Oil · 1 L', 'OIL-005', 'Staples', '1 L pouch · Refined oil', '145.00', '120.00', 50, 15, NULL, 2, '2026-10-28 05:28:34', '2026-10-28 05:28:34'),
(11, 6, 'Amul Milk - 500 ml', 'DAI-001', 'General', 'Imported product', '30.00', '27.00', 60, 10, NULL, 0, '2026-10-28 06:39:13', '2026-10-28 06:39:13'),
(12, 6, 'Aashirvaad Atta - 5 kg', 'STP-003', 'General', 'Imported product', '260.00', '235.00', 12, 10, NULL, 0, '2026-10-28 06:39:13', '2026-10-28 06:39:13'),
(13, 6, 'Tata Tea - 250 g', 'BEV-008', 'General', 'Imported product', '120.00', '100.00', 18, 10, NULL, 0, '2026-10-28 06:39:13', '2026-10-28 06:39:13'),
(14, 6, 'Parle-G - 80 g', 'SNK-002', 'General', 'Imported product', '10.00', '8.00', 24, 10, NULL, 0, '2026-10-28 06:39:13', '2026-10-28 06:39:13'),
(15, 6, 'Surf Excel - 500 g', 'HOM-021', 'General', 'Imported product', '65.00', '58.00', 20, 10, NULL, 0, '2026-10-28 06:39:13', '2026-10-28 06:39:13'),
(16, 3, 'Shop3 Milk', 'S3-DAI-01', 'Dairy', NULL, '30.00', '25.00', 5, 20, 'Active', 0, '2026-10-28 09:10:23', '2026-10-28 09:10:23'),
(114, 2, 'Slow Prod Normal Cost', 'TEST-INS-01', 'Stationery', NULL, '100.00', '60.00', 50, 10, 'active', 0, '2026-10-30 02:51:38', '2026-10-30 02:51:38'),
(115, 2, 'Slow Prod High Cost', 'TEST-INS-02', 'Stationery', NULL, '200.00', '190.00', 30, 5, 'active', 0, '2026-10-30 02:51:38', '2026-10-30 02:51:38'),
(116, 2, 'Slow Prod Unknown Cost', 'TEST-INS-03', 'Stationery', NULL, '150.00', '0.00', 20, 5, 'active', 0, '2026-10-30 02:51:38', '2026-10-30 02:51:38'),
(117, 2, 'Reorder Prod At Min', 'TEST-INS-04', 'Stationery', NULL, '80.00', '50.00', 5, 10, 'active', 0, '2026-10-30 02:51:38', '2026-10-30 02:51:38'),
(118, 2, 'Out Of Stock Prod', 'TEST-INS-05', 'Stationery', NULL, '120.00', '70.00', 0, 5, 'active', 0, '2026-10-30 02:51:38', '2026-10-30 02:51:38'),
(119, 3, 'Shop 3 Exclusive Prod', 'TEST-INS-S3', 'Electronics', NULL, '500.00', '300.00', 40, 5, 'active', 0, '2026-10-30 02:51:38', '2026-10-30 02:51:38'),
(120, 2, 'Notification Low Stock Biscuit', 'NOTIF-01', 'Biscuits', NULL, '50.00', '30.00', 50, 10, NULL, 0, '2026-10-30 02:52:09', '2026-10-30 02:52:10'),
(122, 23, 'Fortune Sunflower Oil · 1 L', 'OIL-005', 'Staples', '1 L pouch · Refined oil', '145.00', '120.00', 49, 15, NULL, 2, '2026-10-30 08:39:37', '2026-10-30 08:54:46'),
(123, 23, 'Surf Excel - 500 g', 'HOM-021', 'Home care', 'Imported product batch', '65.00', '58.00', 20, 10, NULL, 2, '2026-10-30 08:40:34', '2026-10-30 08:40:34'),
(124, 23, 'Amul Milk - 500 ml', 'DAI-001', 'General', 'Imported product batch', '30.00', '27.00', 51, 10, NULL, 2, '2026-10-30 08:40:34', '2026-10-30 08:47:55'),
(142, 23, 'KP Rice 5 kg', 'SKU-KPRI-40122', 'General', 'Imported product', '310.00', '265.00', 20, 10, NULL, 0, '2026-10-31 08:28:53', '2026-10-31 08:28:53'),
(143, 23, 'KP Toor Dal 1 kg', 'SKU-KPTO-47045', 'General', 'Imported product', '165.00', '140.00', 35, 10, NULL, 0, '2026-10-31 08:28:53', '2026-10-31 08:28:53'),
(144, 23, 'KP Sugar 1 kg', 'SKU-KPSU-78E3A', 'General', 'Imported product', '48.00', '41.00', 60, 10, NULL, 0, '2026-10-31 08:28:53', '2026-10-31 08:28:53'),
(145, 23, 'Test Rice 1 kg', 'TST-001', 'Grocery', 'Imported product', '60.00', '52.00', 50, 10, NULL, 0, '2026-10-31 08:32:05', '2026-10-31 08:32:05'),
(146, 23, 'Test Dal 500 g', 'TST-002', 'Grocery', 'Imported product', '82.00', '70.00', 40, 8, NULL, 0, '2026-10-31 08:32:05', '2026-10-31 08:32:05'),
(147, 23, 'Test Soap', 'TST-003', 'Personal Care', 'Imported product', '32.00', '25.00', 30, 6, NULL, 0, '2026-10-31 08:32:05', '2026-10-31 08:32:05'),
(148, 23, 'Test Biscuit', 'TST-004', 'Snacks', 'Imported product', '10.00', '8.00', 80, 15, NULL, 0, '2026-10-31 08:32:05', '2026-10-31 08:32:05'),
(149, 23, 'Test Juice 1 L', 'TST-005', 'Beverages', 'Imported product', '89.00', '70.00', 24, 5, NULL, 0, '2026-10-31 08:32:05', '2026-10-31 08:32:05'),
(150, 23, 'KP Hair Oil 100 ml', '8901000000011', 'General', 'Imported product', '62.00', '0.00', 18, 10, NULL, 0, '2026-10-31 08:33:28', '2026-10-31 08:33:28'),
(151, 23, 'KP Tooth Paste 150 g', '8901000000028', 'General', 'Imported product', '95.00', '0.00', 44, 10, NULL, 0, '2026-10-31 08:33:28', '2026-10-31 08:33:28'),
(152, 23, 'KP Shampoo Sachet', '8901000000035', 'General', 'Imported product', '2.00', '0.00', 200, 10, NULL, 0, '2026-10-31 08:33:28', '2026-10-31 08:33:28'),
(153, 23, 'KP Cooking Oil 1 L', 'KP-OIL-1', 'Grocery', 'Imported product', '135.00', '118.00', 25, 10, NULL, 0, '2026-10-31 08:35:18', '2026-10-31 08:35:18'),
(154, 23, 'KP Butter 100 g', 'KP-BUT-1', 'Dairy', 'Imported product', '56.00', '48.00', 40, 10, NULL, 0, '2026-10-31 08:35:18', '2026-10-31 08:35:18'),
(155, 23, 'KP Biscuit Family Pack', 'SKU-KPBI-7C78D', 'General', 'Imported product', '30.00', '24.00', 48, 10, NULL, 0, '2026-10-31 08:37:42', '2026-10-31 08:37:42'),
(156, 23, 'KP Chips Masala', 'SKU-KPCH-45E12', 'General', 'Imported product', '20.00', '15.00', 70, 10, NULL, 0, '2026-10-31 08:37:42', '2026-10-31 08:37:42'),
(157, 23, 'Test Tea 250 g', 'TST-006', 'Beverages', 'Imported product', '110.00', '90.00', 20, 5, NULL, 0, '2026-10-31 08:40:38', '2026-10-31 08:40:38'),
(158, 23, 'Test Salt 1 kg', 'TST-007', 'Grocery', 'Imported product', '22.00', '18.00', 1, 10, NULL, 0, '2026-10-31 08:40:38', '2026-10-31 08:40:38'),
(159, 23, 'Test Oil 1 L', 'TST-008', 'Grocery', 'Imported product', '145.00', '120.00', 15, 4, NULL, 0, '2026-10-31 08:40:38', '2026-10-31 08:40:38');

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

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `shop_owner_id`, `order_no`, `status`, `total_estimated_cost`, `total_items`, `total_units`, `received_at`, `notes`, `created_at`, `updated_at`) VALUES
(1, 2, 'PO-2026-001', 'received', '540.00', 1, 20, '2026-10-28 14:17:14', 'Automated test replenishment', '2026-10-28 08:47:14', '2026-10-28 08:47:14'),
(2, 2, 'PO-2026-002', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-28 08:47:15', '2026-10-28 08:47:15'),
(3, 2, 'PO-2026-003', 'received', '540.00', 1, 20, '2026-10-28 14:20:13', 'Automated test replenishment', '2026-10-28 08:50:13', '2026-10-28 08:50:13'),
(4, 2, 'PO-2026-004', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-28 08:50:13', '2026-10-28 08:50:13'),
(5, 2, 'PO-2026-005', 'received', '540.00', 1, 20, '2026-10-28 14:31:22', 'Automated test replenishment', '2026-10-28 09:01:22', '2026-10-28 09:01:22'),
(6, 2, 'PO-2026-006', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-28 09:01:22', '2026-10-28 09:01:22'),
(7, 2, 'PO-2026-007', 'received', '540.00', 1, 20, '2026-10-28 14:40:23', 'Automated test replenishment', '2026-10-28 09:10:23', '2026-10-28 09:10:23'),
(8, 2, 'PO-2026-008', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-28 09:10:23', '2026-10-28 09:10:23'),
(9, 2, 'PO-2026-009', 'received', '540.00', 1, 20, '2026-10-28 14:51:33', 'Automated test replenishment', '2026-10-28 09:21:32', '2026-10-28 09:21:33'),
(10, 2, 'PO-2026-010', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-28 09:21:33', '2026-10-28 09:21:33'),
(11, 2, 'PO-2026-011', 'received', '540.00', 1, 20, '2026-10-28 14:55:09', 'Automated test replenishment', '2026-10-28 09:25:09', '2026-10-28 09:25:09'),
(12, 2, 'PO-2026-012', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-28 09:25:09', '2026-10-28 09:25:09'),
(13, 2, 'PO-2026-013', 'received', '540.00', 1, 20, '2026-10-29 08:50:07', 'Automated test replenishment', '2026-10-29 03:20:07', '2026-10-29 03:20:07'),
(14, 2, 'PO-2026-014', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-29 03:20:07', '2026-10-29 03:20:07'),
(15, 2, 'PO-2026-015', 'received', '540.00', 1, 20, '2026-10-29 08:55:59', 'Automated test replenishment', '2026-10-29 03:25:59', '2026-10-29 03:25:59'),
(16, 2, 'PO-2026-016', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-29 03:25:59', '2026-10-29 03:25:59'),
(17, 2, 'PO-2026-017', 'received', '540.00', 1, 20, '2026-10-29 09:08:58', 'Automated test replenishment', '2026-10-29 03:38:58', '2026-10-29 03:38:58'),
(18, 2, 'PO-2026-018', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-29 03:38:58', '2026-10-29 03:38:58'),
(19, 2, 'PO-2026-019', 'received', '540.00', 1, 20, '2026-10-29 09:15:17', 'Automated test replenishment', '2026-10-29 03:45:17', '2026-10-29 03:45:17'),
(20, 2, 'PO-2026-020', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-29 03:45:17', '2026-10-29 03:45:17'),
(21, 2, 'PO-2026-021', 'received', '540.00', 1, 20, '2026-10-29 09:27:36', 'Automated test replenishment', '2026-10-29 03:57:36', '2026-10-29 03:57:36'),
(22, 2, 'PO-2026-022', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-29 03:57:36', '2026-10-29 03:57:36'),
(24, 2, 'PO-2026-023', 'received', '540.00', 1, 20, '2026-10-29 10:18:19', 'Automated test replenishment', '2026-10-29 04:48:18', '2026-10-29 04:48:19'),
(25, 2, 'PO-2026-024', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-29 04:48:19', '2026-10-29 04:48:19'),
(29, 2, 'PO-2026-025', 'received', '540.00', 1, 20, '2026-10-29 14:50:27', 'Automated test replenishment', '2026-10-29 09:20:27', '2026-10-29 09:20:27'),
(30, 2, 'PO-2026-026', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-29 09:20:27', '2026-10-29 09:20:27'),
(32, 2, 'PO-2026-027', 'received', '540.00', 1, 20, '2026-10-30 08:22:18', 'Automated test replenishment', '2026-10-30 02:52:18', '2026-10-30 02:52:18'),
(33, 2, 'PO-2026-028', 'cancelled', '135.00', 1, 5, NULL, NULL, '2026-10-30 02:52:18', '2026-10-30 02:52:18');

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

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`id`, `purchase_order_id`, `product_id`, `quantity`, `cost_price`, `line_estimated_cost`) VALUES
(1, 1, 1, 20, '27.00', '540.00'),
(2, 2, 1, 5, '27.00', '135.00'),
(3, 3, 1, 20, '27.00', '540.00'),
(4, 4, 1, 5, '27.00', '135.00'),
(5, 5, 1, 20, '27.00', '540.00'),
(6, 6, 1, 5, '27.00', '135.00'),
(7, 7, 1, 20, '27.00', '540.00'),
(8, 8, 1, 5, '27.00', '135.00'),
(9, 9, 1, 20, '27.00', '540.00'),
(10, 10, 1, 5, '27.00', '135.00'),
(11, 11, 1, 20, '27.00', '540.00'),
(12, 12, 1, 5, '27.00', '135.00'),
(13, 13, 1, 20, '27.00', '540.00'),
(14, 14, 1, 5, '27.00', '135.00'),
(15, 15, 1, 20, '27.00', '540.00'),
(16, 16, 1, 5, '27.00', '135.00'),
(17, 17, 1, 20, '27.00', '540.00'),
(18, 18, 1, 5, '27.00', '135.00'),
(19, 19, 1, 20, '27.00', '540.00'),
(20, 20, 1, 5, '27.00', '135.00'),
(21, 21, 1, 20, '27.00', '540.00'),
(22, 22, 1, 5, '27.00', '135.00'),
(24, 24, 1, 20, '27.00', '540.00'),
(25, 25, 1, 5, '27.00', '135.00'),
(29, 29, 1, 20, '27.00', '540.00'),
(30, 30, 1, 5, '27.00', '135.00'),
(32, 32, 1, 20, '27.00', '540.00'),
(33, 33, 1, 5, '27.00', '135.00');

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

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `action`, `identifier`, `ip_address`, `attempted_at`) VALUES
(42, 'forgot_password', 'ananya@example.com', '::1', '2026-10-30 08:22:09'),
(43, 'forgot_password', 'nonexistent_email_12345@example.com', '::1', '2026-10-30 08:22:09'),
(50, 'signup', '::1', '::1', '2026-10-30 09:49:47'),
(51, 'signup', '223.236.154.129', '223.236.154.129', '2026-10-30 10:08:22');

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

--
-- Dumping data for table `recommendation_responses`
--

INSERT INTO `recommendation_responses` (`id`, `shop_owner_id`, `recommendation_type`, `product_id_1`, `product_id_2`, `action`, `notes`, `created_at`, `updated_at`) VALUES
(37, 2, 'product_pair', 114, 115, 'dismissed', 'Out of shelf space', '2026-10-30 02:51:39', '2026-10-30 02:51:39'),
(39, 2, 'slow_moving', 114, 0, 'dismissed', 'Keeping seasonal stock', '2026-10-30 02:51:39', '2026-10-30 02:51:39');

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

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `shop_owner_id`, `bill_no`, `sale_date`, `total_amount`, `notes`, `created_at`) VALUES
(510, 2, 'TEST-INS-OLD', '2026-09-15 03:51:38', '100.00', NULL, '2026-10-30 02:51:38'),
(511, 2, 'TEST-INS-B1', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:38'),
(512, 2, 'TEST-INS-B2', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:38'),
(513, 2, 'TEST-INS-B3', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:38'),
(514, 2, 'TEST-INS-B4', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:38'),
(515, 2, 'TEST-INS-B5', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:38'),
(516, 2, 'TEST-INS-B6', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:38'),
(517, 2, 'TEST-INS-B7', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(518, 2, 'TEST-INS-B8', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(519, 2, 'TEST-INS-B9', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(520, 2, 'TEST-INS-B10', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(521, 2, 'TEST-INS-B11', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(522, 2, 'TEST-INS-B12', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(523, 2, 'TEST-INS-B13', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(524, 2, 'TEST-INS-B14', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(525, 2, 'TEST-INS-B15', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(526, 2, 'TEST-INS-B16', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(527, 2, 'TEST-INS-B17', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(528, 2, 'TEST-INS-B18', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(529, 2, 'TEST-INS-B19', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(530, 2, 'TEST-INS-B20', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(531, 2, 'TEST-INS-B21', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(532, 2, 'TEST-INS-B22', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(533, 2, 'TEST-INS-B23', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(534, 2, 'TEST-INS-B24', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(535, 2, 'TEST-INS-B25', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(536, 2, 'TEST-INS-B26', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(537, 2, 'TEST-INS-B27', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(538, 2, 'TEST-INS-B28', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(539, 2, 'TEST-INS-B29', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(540, 2, 'TEST-INS-B30', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(541, 2, 'TEST-INS-B31', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(542, 2, 'TEST-INS-B32', '2026-10-20 03:51:38', '300.00', NULL, '2026-10-30 02:51:39'),
(544, 2, 'BILL-HIST-35DAYS', '2026-09-25 08:22:17', '100.00', NULL, '2026-10-30 02:52:17'),
(545, 2, 'BILL-TEST-1793328745-01', '2026-10-30 03:52:25', '250.00', 'Customer cash sale', '2026-10-30 02:52:25'),
(546, 2, 'BILL-TEST-1793328745-02', '2026-10-30 03:52:25', '250.00', NULL, '2026-10-30 02:52:25'),
(547, 2, 'IMP-GOOD-1793328746', '2026-10-30 03:52:26', '150.00', 'Imported bill', '2026-10-30 02:52:26'),
(548, 23, 'INV-TEST-001', '2026-10-28 14:00:00', '90.00', 'Counter POS cash sale', '2026-10-30 08:44:11'),
(549, 23, 'INV-TEST-002', '2026-10-28 14:00:00', '90.00', 'Counter POS cash sale', '2026-10-30 08:46:40'),
(550, 23, 'INV-TEST-003', '2026-10-30 14:00:00', '90.00', 'Counter POS cash sale', '2026-10-30 08:47:55'),
(551, 23, 'INV-OTHER-001', '2026-10-30 14:24:46', '145.00', NULL, '2026-10-30 08:54:46');

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

--
-- Dumping data for table `sales_items`
--

INSERT INTO `sales_items` (`id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `line_total`) VALUES
(646, 510, 114, 1, '100.00', '100.00'),
(647, 511, 114, 1, '100.00', '100.00'),
(648, 511, 115, 1, '200.00', '200.00'),
(649, 512, 114, 1, '100.00', '100.00'),
(650, 512, 115, 1, '200.00', '200.00'),
(651, 513, 114, 1, '100.00', '100.00'),
(652, 513, 115, 1, '200.00', '200.00'),
(653, 514, 114, 1, '100.00', '100.00'),
(654, 514, 115, 1, '200.00', '200.00'),
(655, 515, 114, 1, '100.00', '100.00'),
(656, 515, 115, 1, '200.00', '200.00'),
(657, 516, 114, 1, '100.00', '100.00'),
(658, 516, 115, 1, '200.00', '200.00'),
(659, 517, 114, 1, '100.00', '100.00'),
(660, 517, 115, 1, '200.00', '200.00'),
(661, 518, 114, 1, '100.00', '100.00'),
(662, 518, 115, 1, '200.00', '200.00'),
(663, 519, 114, 1, '100.00', '100.00'),
(664, 519, 115, 1, '200.00', '200.00'),
(665, 520, 114, 1, '100.00', '100.00'),
(666, 520, 115, 1, '200.00', '200.00'),
(667, 521, 114, 1, '100.00', '100.00'),
(668, 522, 114, 1, '100.00', '100.00'),
(669, 523, 114, 1, '100.00', '100.00'),
(670, 524, 114, 1, '100.00', '100.00'),
(671, 525, 114, 1, '100.00', '100.00'),
(672, 526, 115, 1, '200.00', '200.00'),
(673, 527, 115, 1, '200.00', '200.00'),
(674, 528, 115, 1, '200.00', '200.00'),
(675, 529, 115, 1, '200.00', '200.00'),
(676, 530, 115, 1, '200.00', '200.00'),
(677, 531, 116, 1, '150.00', '150.00'),
(678, 532, 116, 1, '150.00', '150.00'),
(679, 533, 116, 1, '150.00', '150.00'),
(680, 534, 116, 1, '150.00', '150.00'),
(681, 535, 116, 1, '150.00', '150.00'),
(682, 536, 116, 1, '150.00', '150.00'),
(683, 537, 116, 1, '150.00', '150.00'),
(684, 538, 116, 1, '150.00', '150.00'),
(685, 539, 116, 1, '150.00', '150.00'),
(686, 540, 116, 1, '150.00', '150.00'),
(687, 541, 116, 1, '150.00', '150.00'),
(688, 542, 116, 1, '150.00', '150.00'),
(690, 545, 1, 5, '50.00', '250.00'),
(691, 546, 1, 5, '50.00', '250.00'),
(692, 547, 1, 3, '50.00', '150.00'),
(693, 548, 124, 2, '30.00', '60.00'),
(694, 548, 124, 1, '30.00', '30.00'),
(695, 549, 124, 2, '30.00', '60.00'),
(696, 549, 124, 1, '30.00', '30.00'),
(697, 550, 124, 2, '30.00', '60.00'),
(698, 550, 124, 1, '30.00', '30.00'),
(699, 551, 122, 1, '145.00', '145.00');

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

--
-- Dumping data for table `tokens`
--

INSERT INTO `tokens` (`id`, `user_id`, `token_hash`, `expires_at`, `created_at`) VALUES
(28, 3, 'dca7e52800ef194a0b8cfdd240ae00bde1259008fab362b2dccc9fc345e90da0', '2026-11-04 10:19:39', '2026-10-28 09:19:39'),
(30, 3, '93f0d52ccf9e20f83bfa85f690a363732624c2e5b4f9a9725ad7b3722540558e', '2026-11-04 10:20:57', '2026-10-28 09:20:57'),
(32, 3, '3673e3753b3ab978836a32b002d4a5f55d4b3412d2161dc2e8af747b8d04f100', '2026-11-04 10:21:32', '2026-10-28 09:21:32'),
(36, 3, '8a9a69faa17d86bf4f10b5cef8ef5deb35c9ff2521aa42a0cffcedab74161b29', '2026-11-04 10:24:45', '2026-10-28 09:24:45'),
(38, 3, '5259e1244152c399adbe1c83c3b31837c764bdb0b5812e6580c9d08aa1f11d90', '2026-11-04 10:25:09', '2026-10-28 09:25:09'),
(40, 3, '3dcdcf606360165fcceca5459b80cd3f74110fea7113c876d6af2b006fe7d915', '2026-11-04 10:25:53', '2026-10-28 09:25:53'),
(41, 7, 'b7fd1c9aa962e2bede54062e677169257664f82b445f0889ffca3e9f1dc074b0', '2026-11-05 03:49:23', '2026-10-29 02:49:23'),
(43, 3, '16caf8ab6bb6ab85e088ee31eb6f1a353ab9c8a35204d3eec868942799543131', '2026-11-05 04:11:11', '2026-10-29 03:11:11'),
(45, 3, 'cd7d36383e12b99f5815a0dac2abd40294e39596fefe5a20044a03d4022621d6', '2026-11-05 04:18:01', '2026-10-29 03:18:01'),
(52, 3, '7d3887689b619183a29db851c45417452b1d987ee9aa7c518d9e0e78bb4383d8', '2026-11-05 04:19:59', '2026-10-29 03:19:59'),
(54, 3, '05f48c0b9130cdc30bf4ebbc8b17401a9e8b0eff21bb2f2d2e8758cbe9832931', '2026-11-05 04:20:07', '2026-10-29 03:20:07'),
(56, 3, 'eddbc94cff03d146648c3258136201bd22106d7a83762183b9df66c4fb008d2c', '2026-11-05 04:20:46', '2026-10-29 03:20:46'),
(58, 3, '4e718a0d54070b0fb67de2c52fee8c907bc4c2a148bb01d4c3abd9395b0caef7', '2026-11-05 04:21:37', '2026-10-29 03:21:37'),
(63, 3, '12b5bb8822bde5b4a175e1de11e690ef5ddd7f8fc1c7eb5b25237e14a775dbee', '2026-11-05 04:25:58', '2026-10-29 03:25:58'),
(65, 3, '37166ae13526155e813e84eae835ee845a0b00f9262cc3c0c2d69c1129f55424', '2026-11-05 04:25:58', '2026-10-29 03:25:58'),
(67, 3, '0667b23bf6bebe0e79e85b4451bedfd68ad62fa1b66d77a4c240115c28b0c582', '2026-11-05 04:25:59', '2026-10-29 03:25:59'),
(69, 3, '8dd5847e3769fbf1f83b06134db51cd35e0327fd9aee9e2da21c613e938a64cd', '2026-11-05 04:26:01', '2026-10-29 03:26:01'),
(74, 3, '23bd4863016b1518d0453fcc52c124b2f578d4bf7f5bc41de864254eeed0e7a7', '2026-11-05 04:26:16', '2026-10-29 03:26:16'),
(78, 3, 'add31d981557e8a23fcf831aafb5717b1fc5f464d7db3d4f7c2d7e1f216cb573', '2026-11-05 04:38:57', '2026-10-29 03:38:57'),
(80, 3, 'd2be9d7be744a63c2c336bc521d6a765940b473e36fe08511e0805a2b1a90a30', '2026-11-05 04:38:58', '2026-10-29 03:38:58'),
(82, 3, 'c946b5e28757677c6cd4197b8c088aec64ef7fda7d2354c21409f7f0392e15ac', '2026-11-05 04:38:58', '2026-10-29 03:38:58'),
(84, 3, 'fa48d46ca0118589bbad8981e20540eef254c786a691078fec6f47b300102a62', '2026-11-05 04:39:00', '2026-10-29 03:39:00'),
(90, 3, 'bafb6b523f5b516a931074649f7193faf8dae55228f0f1504dd5164d2e9cb78b', '2026-11-05 04:44:49', '2026-10-29 03:44:49'),
(95, 3, '3114fce3e5de0f839c3b84d6cc634e95d634182b18af95ca071c9b99375d2d87', '2026-11-05 04:45:16', '2026-10-29 03:45:16'),
(97, 3, 'f3a1a58477be0ae2b3184e59d851f7aade39d095a54e52ed857af0318c9ebf6a', '2026-11-05 04:45:17', '2026-10-29 03:45:17'),
(99, 3, '0327a292c4a6eb49ca91b81a6a8af2608ea377a5ad888bb0652733b1cc62cf4d', '2026-11-05 04:45:17', '2026-10-29 03:45:17'),
(101, 3, 'e05ee416a6d115575ee0c4d382f5b4603d28945411db8267304f17edfe2b9003', '2026-11-05 04:45:19', '2026-10-29 03:45:19'),
(106, 3, '57b0a2f6b2898c9cb0841b44be2738149ca22e369b202fef5da8a97a7fdadc41', '2026-11-05 04:45:42', '2026-10-29 03:45:42'),
(109, 3, '7e2ec6ff352160609f941aad563f51c5bff1eb7b6e75f878fc41e6ff731ba552', '2026-11-05 04:54:22', '2026-10-29 03:54:22'),
(112, 3, 'a375054af41c7d04fcd009bf74289403b16577620b9cc8160642a2982aea86e5', '2026-11-05 04:54:49', '2026-10-29 03:54:49'),
(114, 3, '57d954f1ed3cada1c133460a3466b8cbfeb3ec408dfc3e0a97d566d847256ed8', '2026-11-05 04:56:51', '2026-10-29 03:56:51'),
(116, 3, '21fc759e14c15d91c9f553c0d40194622fb7ed11edef30afeef34df66b7e4ffd', '2026-11-05 04:57:25', '2026-10-29 03:57:25'),
(120, 3, '9a0cf5129b8644e531fa66275b3928e61e8998aa1dfcf59c900ae4ca3e440041', '2026-11-05 04:57:36', '2026-10-29 03:57:36'),
(122, 3, '7191b2374fc5e8aa6211c59c3b96a17d38a43426436d11da896f2ca30270018a', '2026-11-05 04:57:36', '2026-10-29 03:57:36'),
(124, 3, '3d5f7d0cb66fc326e92ad1c085f7c45b046e5bd0cc4f540465b9454148b92717', '2026-11-05 04:57:37', '2026-10-29 03:57:37'),
(126, 3, '55f1e0bfc72525a14fb5887b995fac90edb3bfd206bd3c6057c7c307cce674c8', '2026-11-05 04:57:38', '2026-10-29 03:57:38'),
(129, 3, '3251e5df1360022dc56c0040244dddb00af38f664601fdeaf33743f5ed9f6564', '2026-11-05 05:34:25', '2026-10-29 04:34:25'),
(133, 3, 'e0cb39ec1e1e47c08b93560bd91a7c65f415deea1e9eeb0a7b82a6c34a6d496f', '2026-11-05 05:36:09', '2026-10-29 04:36:09'),
(136, 8, '7b0b9bb26f044239d41cc963507708ba98a2161dc22d5d53fed2091d8fb7a3d5', '2026-11-05 05:36:10', '2026-10-29 04:36:10'),
(138, 3, '0b9d1b094d22d9309a62a06333ff9ff0f56846c8dbc9a5d8084ba72c2cbedc38', '2026-11-05 05:40:02', '2026-10-29 04:40:02'),
(145, 3, '1242dd1b4ef160821915761d9e96701cdbb751854d14e7619cbfba22489b27a1', '2026-11-05 05:48:04', '2026-10-29 04:48:04'),
(147, 3, 'a539eec878df8955e01a4e1958341e9685747985f94b39e5f03cc2cc74faf1e4', '2026-11-05 05:48:18', '2026-10-29 04:48:18'),
(149, 3, '0c2090cfb3d2ae641234196231ed4ede961a61b0f676f53460faa49ca9cee277', '2026-11-05 05:48:27', '2026-10-29 04:48:27'),
(151, 3, 'a3abc57564e1d2cdce7dfec38f234a1b5184206421f4cf9a0d9dcabb0be27752', '2026-11-05 05:48:43', '2026-10-29 04:48:43'),
(154, 3, '82b1182bc95cd3dcaa82deedd23bb2fcfb8a5f7e03655417af950d6dfa22b78e', '2026-11-05 05:49:05', '2026-10-29 04:49:05'),
(159, 3, 'becb6b2ea624d73e7e2a51f7d3a3ca5029617f8c783a86bb5cbcf0b549b90d4f', '2026-11-05 06:01:26', '2026-10-29 05:01:26'),
(163, 7, '6fe1a1cae1bdadf5120684c98f800775606306669635507454623202a87d8bab', '2026-11-05 07:48:10', '2026-10-29 06:48:10'),
(164, 7, 'e18f0ce0a740c1fc737bb6493f0a52c972266a991932c7eb9634b42dbaeb5026', '2026-11-05 07:50:16', '2026-10-29 06:50:16'),
(165, 7, '6563e5215b6b0d5068d3a307fd840649f7f8206e5769297afac53498029891b6', '2026-11-05 08:12:39', '2026-10-29 07:12:39'),
(166, 7, '1274f65b7b75978882086e28ce3fc5367397231c37df0ba57a9e687d36eaccdb', '2026-11-05 08:13:26', '2026-10-29 07:13:26'),
(167, 7, '8f6c57e42f7e70aca0bc9bb5ea49bd9079e3e200dc403e2abba12f8ee5742f27', '2026-11-05 08:14:21', '2026-10-29 07:14:21'),
(168, 7, 'e8df9f2b593f18ed13b399e37b9963a74f8b677966a9990eac2e0593d53089a6', '2026-11-05 08:19:56', '2026-10-29 07:19:56'),
(169, 7, '00a531a5cc6150846822e3bd74468e19085b156bbc962c2c7b0c46939a926662', '2026-11-05 13:27:05', '2026-10-29 07:57:05'),
(170, 13, 'f936256bae44debb32c046b3b4ce131c25cd2736375d1322441f62ec5754d384', '2026-11-05 09:34:31', '2026-10-29 08:34:31'),
(171, 13, 'a49f51c7f009537351f14d3fbfbae75449fe81739beeecf4ef6984b58757a1e5', '2026-11-05 10:10:48', '2026-10-29 09:10:48'),
(175, 3, '016f977cb4f2f3ae70ae72b3cf41840342dabdebbe05802606caee6c6477bdb2', '2026-11-05 10:19:37', '2026-10-29 09:19:37'),
(177, 3, '6cf1817e0f70a3e84d5165ca842edc1896c3ac63f8bf64aabe2b9134b9484225', '2026-11-05 10:19:45', '2026-10-29 09:19:45'),
(183, 3, '9d319741979ac5287275c11bf90bf921b1fc0ba77405c4e0f84548c5540ffb54', '2026-11-05 10:20:27', '2026-10-29 09:20:27'),
(185, 3, '48c1f65505599f45f6c22d5237795de0bf73f41523ab6f540397ccbfa038e6bd', '2026-11-05 10:20:33', '2026-10-29 09:20:33'),
(187, 3, '47ebc5283f3842e5d81c5531adf7a3c36560310aabb6ded188b63ca60345db9b', '2026-11-05 10:20:41', '2026-10-29 09:20:41'),
(195, 3, '74e3bd1cbe01041debc588ff2847e9af98bd427c4165460f93492119f21b5377', '2026-11-06 03:51:38', '2026-10-30 02:51:38'),
(197, 3, '90e11b50fb74cf189eee8b0a0d32f1c335765453f60399752bf49b45e9a2d718', '2026-11-06 03:52:09', '2026-10-30 02:52:09'),
(203, 3, '0de158c28dbe45845f648f7c2bdcddc57e6e915bda67b582058c000225c46bbf', '2026-11-06 03:52:17', '2026-10-30 02:52:17'),
(205, 3, 'b67bb78f27cdb88074396d9e985b91e264b817aca59d13e3ac4512e2bb41ddae', '2026-11-06 03:52:25', '2026-10-30 02:52:25'),
(206, 2, '157a71d85dbf0439b7d345563ec349e3f0f8c45fa044b6f9686185721822a983', '2026-11-06 03:52:33', '2026-10-30 02:52:33'),
(207, 3, 'd112d6a0de716bd08839fa861e2c8c683e98924d161f5359dae977c003886bb4', '2026-11-06 03:52:33', '2026-10-30 02:52:33'),
(210, 12, 'd6dd7d0c65fc2a7103503cea1c5b34797ad3f512382e4118cd89800a93a85fad', '2026-11-06 04:39:00', '2026-10-30 03:39:00'),
(215, 23, 'cc3f3f8912cbf4b3ab7f2a879cdaf3fbb971a4d440f78590799cbb30f2b6494f', '2026-11-06 05:38:22', '2026-10-30 04:38:22'),
(216, 23, '84fd63ab554b1b46a18759c1da3c14380e88d8e5ef72617b7f071d5b609cbc8d', '2026-11-06 05:40:41', '2026-10-30 04:40:41'),
(217, 23, 'c5f80f6425ce0a7649e10092a6a0fad1da0bd67ec0744425af437b394cf4b3ca', '2026-11-06 05:44:33', '2026-10-30 04:44:33'),
(220, 23, '921dd4406cdf4ffac6efe267b4d04a4e1ba6f003d0a344480a745a33226047d8', '2026-11-06 09:38:53', '2026-10-30 08:38:53'),
(221, 2, 'ee8c7cdfdd0b4df0cb1eb20b65153134823c9cbb6f855775263d51ebcdea1c03', '2026-10-31 10:13:41', '2026-10-30 09:13:41'),
(222, 23, '0c107fda0842d16a108d4c1f3962ee98f4aacbe223ad8009be43e11e5277843b', '2026-11-07 06:23:17', '2026-10-31 05:23:17'),
(223, 24, '4dcddb333ba478662f37c9f8b65dddabfc9bb735eebb69b21d00230384b59f3d', '2026-11-07 07:50:40', '2026-10-31 06:50:40'),
(224, 2, 'e9258a52ae029f97ea70c4326dd997da81f376e2eb53bd3fb42d1943d26e78a4', '2026-11-07 07:54:04', '2026-10-31 06:54:04'),
(225, 2, '2666caccb9468d6b4eda879842cb25fd99484b61f1d325fe3a295813ce647766', '2026-11-07 07:56:01', '2026-10-31 06:56:01'),
(226, 2, 'aa7dde7e66301cf5992e4eebb2849c3e87930a74e8fb0984bd399496eab991af', '2026-11-07 07:57:45', '2026-10-31 06:57:45');

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
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `low_stock_alerts`, `ai_recommendations`, `daily_sales_summary`, `weekly_email_digest`, `theme_mode`, `updated_at`, `lead_time_days`, `safety_days`, `cover_days`, `min_history_days`, `slow_moving_threshold_days`, `import_mode`, `timezone`, `failed_password_attempts`, `password_locked_until`) VALUES
(2, 3, 1, 1, 1, 0, 'SYSTEM', '2026-10-27 08:08:40', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(3, 4, 1, 1, 1, 0, 'SYSTEM', '2026-10-27 08:08:40', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(5, 5, 1, 1, 1, 0, 'SYSTEM', '2026-10-27 08:16:49', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(6, 6, 1, 1, 1, 0, 'SYSTEM', '2026-10-28 04:52:23', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(7, 7, 1, 1, 1, 0, 'SYSTEM', '2026-10-29 02:49:14', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(57, 8, 1, 1, 1, 0, 'SYSTEM', '2026-10-29 04:36:10', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(67, 12, 1, 1, 1, 0, 'SYSTEM', '2026-10-29 08:30:03', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(68, 13, 1, 1, 1, 0, 'SYSTEM', '2026-10-29 08:34:02', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(80, 2, 1, 1, 1, 0, 'SYSTEM', '2026-10-30 02:52:35', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL),
(89, 23, 1, 1, 1, 0, 'SYSTEM', '2026-10-30 04:38:22', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata', 0, NULL);

--
-- Indexes for dumped tables
--

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
  ADD KEY `idx_shop_units` (`shop_owner_id`,`units`);

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
-- AUTO_INCREMENT for table `import_history`
--
ALTER TABLE `import_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `import_mappings`
--
ALTER TABLE `import_mappings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `notification_states`
--
ALTER TABLE `notification_states`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `owner_account`
--
ALTER TABLE `owner_account`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `recommendation_responses`
--
ALTER TABLE `recommendation_responses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=552;

--
-- AUTO_INCREMENT for table `sales_items`
--
ALTER TABLE `sales_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=700;

--
-- AUTO_INCREMENT for table `tokens`
--
ALTER TABLE `tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=228;

--
-- AUTO_INCREMENT for table `user_settings`
--
ALTER TABLE `user_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- Constraints for dumped tables
--

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
