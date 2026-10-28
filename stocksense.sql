-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 27, 2026 at 08:30 AM
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
-- Table structure for table `owner_account`
--

CREATE TABLE `owner_account` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `shop_name` varchar(150) NOT NULL,
  `location` varchar(255) NOT NULL DEFAULT 'Indiranagar, Bengaluru',
  `store_type` varchar(100) NOT NULL DEFAULT 'Neighbourhood supermarket',
  `currency` varchar(50) NOT NULL DEFAULT '₹ INR / IST',
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `owner_account`
--

INSERT INTO `owner_account` (`id`, `full_name`, `shop_name`, `location`, `store_type`, `currency`, `email`, `password`, `created_at`) VALUES
(2, 'Ananya Rao', 'Fresh Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'ananya@example.com', '$2y$10$W8T3pylwM2Db8ClfJSaroeRUSr4Ity7S.p29J3lu/apt3eT3EYecS', '2026-10-26 04:39:32'),
(3, 'daksh', 'Fresh Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'daksh1@gmail.com', '$2y$10$1nCja8GhLmBNc3meILbOd.1W0d2SGh8bimtW54TpnokDU.5DaIV0y', '2026-10-26 04:43:23'),
(4, 'Isha', 'Jio Supermart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'kotharidaksh@gmail.com', '$2y$10$0tpQXI7/g/T6Jtoj9MvC8egiMBeqv.ZDLxPg039s6gU0ftlx0kG2O', '2026-10-27 05:11:01');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `low_stock_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `ai_recommendations` tinyint(1) NOT NULL DEFAULT 1,
  `daily_sales_summary` tinyint(1) NOT NULL DEFAULT 1,
  `weekly_email_digest` tinyint(1) NOT NULL DEFAULT 0,
  `theme_mode` varchar(20) NOT NULL DEFAULT 'SYSTEM',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_settings_owner` FOREIGN KEY (`user_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `low_stock_alerts`, `ai_recommendations`, `daily_sales_summary`, `weekly_email_digest`, `theme_mode`) VALUES
(1, 2, 1, 1, 1, 0, 'SYSTEM'),
(2, 3, 1, 1, 1, 0, 'SYSTEM'),
(3, 4, 1, 1, 1, 0, 'SYSTEM');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shop_sku` (`shop_owner_id`, `sku`),
  KEY `idx_shop_owner` (`shop_owner_id`),
  KEY `idx_shop_units` (`shop_owner_id`, `units`),
  CONSTRAINT `fk_products_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `shop_owner_id`, `name`, `sku`, `category`, `description`, `price`, `cost_price`, `units`, `min_stock_threshold`, `status`, `icon_type`) VALUES
(1, 2, 'Amul Milk · 500 ml', 'DAI-001', 'Dairy', '500 ml · Fresh daily', 30.00, 27.00, 8, 24, 'Low stock · Minimum 24', 0),
(2, 2, 'Britannia Marie · 150 g', 'SNK-014', 'Snacks', '150 g · Tea-time biscuits', 30.00, 25.00, 96, 30, 'Slow moving · 6 sold in 30 days', 1),
(3, 2, 'Aashirvaad Atta - 5 kg', 'STP-003', 'Staples', '5 kg · Whole wheat atta', 260.00, 235.00, 24, 15, NULL, 2),
(4, 2, 'Tata Tea - 250 g', 'BEV-008', 'Beverages', '250 g · Premium leaf tea', 120.00, 100.00, 42, 20, NULL, 3),
(5, 2, 'Parle-G · 80 g', 'SNK-002', 'Snacks', '80 g · Glucose biscuits', 10.00, 8.00, 120, 40, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `import_history`
--

CREATE TABLE `import_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `success_count` int(11) NOT NULL DEFAULT 0,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('completed', 'failed', 'partial') NOT NULL DEFAULT 'completed',
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_import_owner` (`shop_owner_id`),
  CONSTRAINT `fk_import_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `tokens`
--

CREATE TABLE `tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_token_user_id` (`user_id`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `bill_no` varchar(50) NOT NULL,
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shop_bill` (`shop_owner_id`, `bill_no`),
  KEY `idx_sales_owner_date` (`shop_owner_id`, `sale_date`),
  CONSTRAINT `fk_sales_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `sales_items`
--

CREATE TABLE `sales_items` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_sales_items_sale` (`sale_id`),
  KEY `idx_sales_items_product` (`product_id`),
  CONSTRAINT `fk_sales_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `order_no` varchar(50) NOT NULL,
  `status` enum('placed','received','cancelled') NOT NULL DEFAULT 'placed',
  `total_estimated_cost` decimal(10,2) DEFAULT NULL,
  `total_items` int(11) NOT NULL DEFAULT 0,
  `total_units` int(11) NOT NULL DEFAULT 0,
  `received_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shop_order` (`shop_owner_id`, `order_no`),
  KEY `idx_orders_owner_status` (`shop_owner_id`, `status`),
  CONSTRAINT `fk_po_shop_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `line_estimated_cost` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_poi_order` (`purchase_order_id`),
  KEY `idx_poi_product` (`product_id`),
  CONSTRAINT `fk_poi_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;



