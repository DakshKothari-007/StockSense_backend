-- Insights Module Database Migration for StockSense
-- Creates recommendation_responses table and adds composite index on sales_items

CREATE TABLE IF NOT EXISTS `recommendation_responses` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_owner_id` int(10) UNSIGNED NOT NULL,
  `recommendation_type` enum('slow_moving','product_pair','reorder') NOT NULL,
  `product_id_1` int(10) UNSIGNED NOT NULL,
  `product_id_2` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `action` enum('accepted','dismissed') NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_owner_rec` (`shop_owner_id`, `recommendation_type`, `product_id_1`, `product_id_2`),
  KEY `idx_rec_owner_type` (`shop_owner_id`, `recommendation_type`),
  CONSTRAINT `fk_rec_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Composite index on sales_items for fast co-occurrence pair queries
ALTER TABLE `sales_items` ADD INDEX `idx_sale_product` (`sale_id`, `product_id`);
