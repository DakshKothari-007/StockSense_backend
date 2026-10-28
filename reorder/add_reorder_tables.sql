-- Reorder Module Database Migration for StockSense
-- Creates purchase_orders and purchase_order_items tables

CREATE TABLE IF NOT EXISTS `purchase_orders` (
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

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
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
