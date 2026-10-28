-- Migration: Add index on products(shop_owner_id, units) for fast dashboard metric queries
ALTER TABLE `products` ADD INDEX `idx_shop_units` (`shop_owner_id`, `units`);
