# Changelog - StockSense Backend

## [Settings Module Release] - October 2026

### Added
- **Database Schema Additions (`settings/add_settings_columns.sql`):**
  - Idempotent migration adding configuration, calculation, and security columns to `user_settings`:
    - `lead_time_days INT UNSIGNED NOT NULL DEFAULT 2`
    - `safety_days INT UNSIGNED NOT NULL DEFAULT 3`
    - `cover_days INT UNSIGNED NOT NULL DEFAULT 7`
    - `min_history_days INT UNSIGNED NOT NULL DEFAULT 30`
    - `slow_moving_threshold_days INT UNSIGNED NOT NULL DEFAULT 60`
    - `import_mode ENUM('ADD','REPLACE') NOT NULL DEFAULT 'ADD'`
    - `timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Kolkata'` (read-only)
    - `failed_password_attempts INT UNSIGNED NOT NULL DEFAULT 0`
    - `password_locked_until DATETIME DEFAULT NULL`
- **Centralized Settings Helper (`settings/settings_helper.php`):**
  - `get_shop_settings(PDO $pdo, int $shopId): array`: Single centralized helper used by `reorder/`, `insights/`, `dashboard/`, and `import/`. Performs read-only lookups and returns strictly typed default settings when unconfigured without writing to the database.
- **Settings Endpoints (`settings/`):**
  - `settings/get.php` (`GET`): Read-only endpoint returning all account, appearance, notification, and inventory parameters. Returns defaults if no row exists in DB without performing any write operation.
  - `settings/update.php` (`POST`): Partial preference update endpoint with strict type checking (ints as ints, booleans as booleans), range validation (lead: 0-60, safety: 0-60, cover: 1-90, min_history: 7-180, slow_moving: 14-365), unknown key rejections, read-only timezone enforcement (400), profile field redirection (400), and email update with current password verification and uniqueness check. Upserts into `user_settings` on first update.
  - `settings/change_password.php` (`POST`): Verifies current password against database hash, locks account for 15 minutes after 5 consecutive failures, enforces min 8 characters, enforces that the new password differs from current, updates hash, and atomically revokes all other active session tokens from `tokens` table while preserving the current active session.
  - `settings/reset_defaults.php` (`POST`): Restores default system values for all configurable settings in a transaction.
- **Automated Test Runner (`test_settings_backend.php`):**
  - 65 automated tests covering authentication, method checks, read-only get defaults without writing, strict type validation, range checking, unknown key rejection, read-only timezone rejection, dynamic reorder and insights integration, multi-shop isolation, REPLACE import safety confirmation, password changes, token revocation, failed attempt locking, and backward-compatible thin wrappers.

### Changed
- **`auth/` Backward Compatibility Wrappers:**
  - `auth/get_settings.php`, `auth/update_settings.php`, and `auth/change_password.php` now act as thin wrappers delegating to the unified `settings/` implementations.
- **`reorder/list.php`:**
  - Dynamically reads `lead_time_days`, `safety_days`, `cover_days`, and `min_history_days` from `get_shop_settings()`.
- **`insights/slow_moving.php`:**
  - Dynamically reads `slow_moving_threshold_days`, `min_history_days`, and reorder thresholds from `get_shop_settings()`.
- **`dashboard/summary.php`:**
  - Dynamically reads `min_history_days` and `slow_moving_threshold_days` from `get_shop_settings()`.
- **`import/execute.php`:**
  - Dynamically reads `import_mode` from `get_shop_settings()`. When `import_mode` is `'REPLACE'`, requires explicit `confirm_replace: true` in the request body before overwriting stock quantities; uses standard additive updates when set to `'ADD'`.
- **Postman Collection (`StockSense_Postman_Collection.json`):**
  - Replaced `"3. App Settings"` with `"3. Settings Module"` containing 7 requests with Bearer token authentication, input validations, error tests, password changes, and defaults reset.

## [Insights Module & Reorder Fix] - October 2026

### Added
- **Database Tables & Indexes (`recommendation_responses`, `idx_sale_product`):**
  - Added migration script `insights/add_insights_tables.sql` and updated `stocksense.sql`:
    - `recommendation_responses`: `id`, `shop_owner_id`, `recommendation_type` (`slow_moving`, `product_pair`, `reorder`), `product_id_1`, `product_id_2` (`INT UNSIGNED NOT NULL DEFAULT 0` to enable non-null unique indexing), `action` (`accepted`, `dismissed`), `notes`, `created_at`, `updated_at`. Unique key on `(shop_owner_id, recommendation_type, product_id_1, product_id_2)`.
    - Added index on `sales_items(sale_id, product_id)` for high-performance co-occurrence pair queries.
- **Insights Endpoints (`insights/`):**
  - `insights/slow_moving.php` (`GET`): Identifies products with `units > 0 AND (stock_cover_days >= 60 OR units_sold_30_days = 0)`. Excludes products currently on the reorder list. Returns named configuration (`single_discount_pct = 10`, `bundle_discount_pct = 15`), clearance suggested price, margin status (`above cost`, `below cost`, or `cost unknown`), and response status. Supports `?include_dismissed=1`.
  - `insights/product_pairs.php` (`GET`): Analyzes frequent co-purchases over a 90-day window (`min_total_bills = 30`, `min_bills_together = 3`). Uses a single grouped query for individual bill counts and pairwise co-occurrence. Computes Support, Confidence, and Lift. Filters for `lift > 1.0` and validated `min_support` ($0 \le \text{min\_support} \le 1$). Uses neutral template `"Often bought together: {A} + {B}"` and smaller-ID-first convention (`pair_min_max`).
  - `insights/respond.php` (`POST`): Records or updates shop recommendation action (`accepted` or `dismissed`). Validates product ownership per shop. Automatically orders pair IDs smaller first. Upserts with `ON DUPLICATE KEY UPDATE` so latest response wins.
  - `insights/history.php` (`GET`): Paginated audit history of recommendation responses, newest first, with optional type and action filters.
- **Automated Test Runner (`test_insights_backend.php`):**
  - 38 automated test cases covering authentication, methods, slow-moving clearance rules, below/above cost warnings, 90-day pair lift calculations, neutral headline templates, smaller-ID-first ordering, cross-shop isolation, response upserts, and dismissal filtering.

### Changed
- **`reorder/list.php`:**
  - Zero-demand fix: If the shop HAS $\ge 30$ days of sales history and a product sold 0 in 30 days, returns `suggested_quantity = null`, `estimated_cost = null`, and `reason = "No sales in 30 days. Review before reordering."`. The 2x-minimum fallback applies only when the entire shop lacks $\ge 30$ days history.
- **`test_reorder_backend.php`:**
  - Added data setup seeding a historical bill 35 days ago for Shop 2 to test $\ge 30$ days shop history, and ensured Shop 3 has a test product for testing $< 30$ days fallback. Assertions expanded from 26 to 29 tests (all 29 passing).
- **Postman Collection (`StockSense_Postman_Collection.json`):**
  - Added folder `"8. Insights Module"` with 6 ready-to-run requests for slow-moving, product pairs, responding to recommendations, and response history.

## [Reorder Module Release] - October 2026

### Added
- **Database Tables (`purchase_orders`, `purchase_order_items`):**
  - Added migration script `reorder/add_reorder_tables.sql` and updated `stocksense.sql`:
    - `purchase_orders`: `id`, `shop_owner_id`, `order_no`, `status` (`placed`, `received`, `cancelled`), `total_estimated_cost`, `total_items`, `total_units`, `received_at`, `notes`, `created_at`. Unique key on `(shop_owner_id, order_no)` and index on `(shop_owner_id, status)`.
    - `purchase_order_items`: `id`, `purchase_order_id`, `product_id`, `quantity`, `cost_price`, `line_estimated_cost`. Indexes on `purchase_order_id` and `product_id`.
- **Reorder Endpoints (`reorder/`):**
  - `reorder/list.php` (`GET`): Explainable, rule-based reorder recommendations with named `REORDER_CONFIG` (`lead_time_days`, `safety_days`, `cover_days`), `daily_sales_rate` (`sold_last_30_days / 30`), `days_of_stock_left` (`null` when rate is 0), open on-order deductions (`on_order_units`), explicit priority rules, and 2x-minimum threshold fallback when history is under 30 days. Global totals cover all pages.
  - `reorder/create_order.php` (`POST`): Creates purchase order with initial status `'placed'` and server-generated `order_no` (`PO-YYYY-NNN` per shop). Enforces max 100 items per order and 10,000 units per item.
  - `reorder/orders.php` (`GET`): Lists purchase orders with status filter and single-query items loader.
  - `reorder/receive.php` (`POST`): Atomically updates status to `'received'` and adds received quantities to `products.units` in one transaction. Prevents receiving twice.
  - `reorder/cancel.php` (`POST`): Cancels open orders (`placed`). Cancelled orders cannot be received.
- **Automated Test Runner (`test_reorder_backend.php`):**
  - 26 automated tests covering rule evaluation, fallback logic, null cost_price, open order subtractions, order creation, receipt, double-receipt rejection, cancellation, and shop isolation.

### Changed
- **Postman Collection (`StockSense_Postman_Collection.json`):**
  - Added folder `"7. Reorder Module"` with 7 copy-ready requests and test assertions.

## [Sales Module Release] - October 2026

### Added
- **Database Tables (`sales`, `sales_items`):**
  - Added migration script `sales/add_sales_tables.sql` and updated `stocksense.sql`:
    - `sales`: `id`, `shop_owner_id`, `bill_no`, `sale_date`, `total_amount`, `notes`, `created_at`. Unique key on `(shop_owner_id, bill_no)` and index on `(shop_owner_id, sale_date)`.
    - `sales_items`: `id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `line_total`. Indexes on `sale_id` and `product_id`.
- **Sales Endpoints (`sales/`):**
  - `sales/create.php` (`POST`): Records sale bill, ignores client `unit_price` in favor of DB `products.price`, merges duplicate product lines per bill, performs atomic stock deduction (`UPDATE products SET units = units - ? WHERE id=? AND shop_owner_id=? AND units >= ?`), and rolls back with a detailed 400 if stock is insufficient.
  - `sales/list.php` (`GET`): Paginated bills (`page`, `limit`), filtered by half-open date range (`sale_date >= ? AND sale_date < ?`), with single-query items loader.
  - `sales/summary.php` (`GET`): Period total sales, bills count, average bill, daily sales points, top selling products, category breakdown, and hourly sales for today when real timestamps exist.
  - `sales/import.php` (`POST`): All-or-nothing sales import (JSON or CSV), reports row number and reason for bad rows, groups rows by `bill_no`, supports optional `deduct_stock` (default `false`), and logs import history.
- **Automated Test Runner (`test_sales_backend.php`):**
  - 37 automated tests covering creation, stock deduction, rollback, duplicate bills, bad dates, shop isolation, single-query loading, hourly sales, all-or-nothing import, and product deletion prevention.

### Changed
- **`dashboard/summary.php`:**
  - Replaced zeros with live queries on `sales` and `sales_items` for today's sales, weekly sales, daily points, and top sellers.
  - Updated slow-moving calculation using real 30-day sales velocity (`sold_last_30_days`, `stock_cover_days`), shows rule in response, and sets `"has_enough_sales_history" => false` when under 30 days of sales exist.
- **`inventory/delete_product.php`:**
  - Rejects product deletion with clear HTTP 400 error when linked to existing sales records to preserve audit and financial integrity.
- **Postman Collection (`StockSense_Postman_Collection.json`):**
  - Added folder `"6. Sales Module"` with 9 copy-ready requests and test scripts.

## [Token Authentication Release] - October 2026

### Added
- **Database Table (`tokens`):**
  - Schema added to database and `stocksense.sql`:
    - `id INT AUTO_INCREMENT PRIMARY KEY`
    - `user_id INT UNSIGNED NOT NULL` (Foreign key to `owner_account(id)` on delete cascade)
    - `token_hash CHAR(64) NOT NULL UNIQUE` (Stores SHA-256 hash of token)
    - `expires_at DATETIME NOT NULL`
    - `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
- **Automated Verification Script (`test_token_auth.php`):**
  - Standalone CLI test suite validating 14 test cases covering token generation, SHA-256 hash persistence, non-expired verification, 401 rejections for raw user IDs and emails, and token revocation on logout.

### Changed
- **`auth/login.php`:**
  - Now generates a cryptographically secure 64-character token via `bin2hex(random_bytes(32))`.
  - Calculates its SHA-256 hash and inserts into `tokens` with a 7-day expiration date (`expires_at = NOW() + 7 days`).
  - Returns `"token"` and `"expires_at"` in response JSON while preserving all user profile data.
- **`inventory/auth_helper.php` (`authenticateShopOwner()`):**
  - Completely replaced temporary user ID / email bypasses with token hash validation.
  - Computes `hash('sha256', $token)` and queries `tokens` joined with `owner_account` where `expires_at > NOW()`.
  - Passing `Bearer 2`, an email, an invalid token, or an expired token strictly triggers `HTTP 401 Unauthorized`.
  - All existing endpoints across `inventory/`, `import/`, and `dashboard/` continue functioning without requiring any modifications to their individual controllers.
- **`auth/logout.php`:**
  - Extracts Bearer token from headers, hashes with SHA-256, and deletes the matching token row from `tokens`.
- **Postman Collections (`StockSense_Postman_Collection.json` & `StockSense_Auth_Profile_Postman_Collection.json`):**
  - Updated `Login` request with Postman test script to automatically parse `response.token` and store it into the `{{token}}` collection variable.
  - Updated `Logout` request with `Authorization: Bearer {{token}}` and test assertions.
  - Added dedicated test requests verifying that sending `Bearer 2` or `Bearer <email>` yields `401 Unauthorized`.

## [Dashboard Module Release] - October 2026

### Added
- **Database Migration:**
  - `dashboard/add_index_products_units.sql`: Added composite index `idx_shop_units` on `products(shop_owner_id, units)` to ensure rapid aggregation for inventory value and threshold checks.
- **Dashboard APIs (`dashboard/`):**
  - `dashboard/summary.php` (`GET`): Returns full store overview for the authenticated owner:
    - Active product count and total inventory value (`SUM(units * price)`).
    - Low-stock counter and items list (`units > 0 AND min_stock_threshold > 0 AND units <= min_stock_threshold`).
    - Out-of-stock counter and items list (`units = 0`).
    - Slow-moving items list filtered strictly on `status LIKE '%Slow moving%'`.
    - AI Priorities: dynamic reorder recommendation with rule `"Rule: order up to 2x minimum"` and estimated purchase cost; slow-moving clearance recommendation.
    - Category breakdown with product count, total units, value, and share percentage (empty categories labeled `"Uncategorised"`).
    - Latest import history metadata.
    - Optional date range validation (`from` and `to` formatted as `YYYY-MM-DD`, `from <= to`). Returns HTTP 400 on invalid format.
    - Strictly plain numbers (all formatted strings omitted per spec).
- **Postman Collection Update:**
  - Added folder `"5. Dashboard Module"` with 4 copy-ready requests covering default date range, custom range, validation errors (400), and unauthorized checks (401).

## [Import Module Release] - October 2026

### Added
- **Database Table:**
  - `import_history`: Logs file metadata, total rows, success count, failure count, execution status (`completed`, `failed`, `partial`), JSON execution details/metrics, and timestamps.
- **Spreadsheet Reader Engine:**
  - `import/SpreadsheetReader.php`: Native dependency-free parser for CSV (with delimiter detection and UTF-8 BOM stripping) and Excel `.xlsx` (using `ZipArchive` and `SimpleXML` to read shared strings and sheet cells).
- **Import APIs (`import/`):**
  - `import/upload.php` (`POST`): Handles multipart upload, enforces $\le 5\text{ MB}$ limit, restricts allowed extensions (`.csv`, `.xlsx`, `.xls`), generates secure owner-scoped token, parses initial rows, and suggests column mappings.
  - `import/preview.php` (`POST`): Previews column mappings and table rows with current stock levels, unit costs, sell prices, and error highlighting.
  - `import/validate.php` (`POST`): Validates every row, detects stray letters (e.g. `18O` instead of `18`), flags missing required fields or negative prices, suggests auto-fixes, and calculates stock & value addition summaries.
  - `import/execute.php` (`POST`): Atomic database transaction with rollback. Updates existing SKUs (stock receipt behavior: `units = units + added_units`), inserts new products, logs to `import_history`, and returns full summary metrics.
  - `import/get_history.php` (`GET`): Returns chronological history of imports for the authenticated shop owner.
- **Sample Test Files:**
  - `sample_valid_import.csv`: Clean sample CSV matching store inventory.
  - `sample_error_import.csv`: Sample CSV containing error cases (`18O` stray letter quantity and missing SKU).
  - `sample_stock_oct15.xlsx`: Sample `.xlsx` spreadsheet matching UI mockups.
- **Postman Collection Update:**
  - Added folder `"4. Import Module (Spreadsheet & Stock Receipt)"` containing all 5 requests with pre-configured headers, multipart file upload, and body templates.

### Security & Data Isolation
- Strict shop owner data isolation: tokens and product rows are strictly verified against the authenticated user ID (`shop_owner_id`).
- Protection against invalid SQL placeholders for native prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`).
