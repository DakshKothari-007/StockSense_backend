# Changelog - StockSense Backend

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
