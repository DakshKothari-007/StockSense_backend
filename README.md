# StockSense Backend APIs

StockSense backend REST APIs built with PHP 8.1 and MySQL (XAMPP environment).

## Project Structure

```
StockSense_backend
├── auth/                               # Authentication module
│   ├── change_password.php             # Password change
│   ├── forgot_password.php             # Password reset request
│   ├── login.php                       # Owner login
│   ├── profile.php                     # Profile info & settings
│   ├── reset_password.php              # Password reset confirmation
│   ├── signup.php                      # Owner signup
│   ├── update_profile.php              # Update owner name & preferences
│   └── update_shop.php                 # Update store name, location, etc.
├── config/
│   └── db.php                          # PDO MySQL connection
├── dashboard/                          # Dashboard & Analytics Module
│   ├── add_index_products_units.sql    # Migration: index on products(shop_owner_id, units)
│   └── summary.php                     # Store overview, alerts, AI priorities & categories
├── import/                             # Bulk Spreadsheet Import Module
│   ├── SpreadsheetReader.php           # Native CSV & XLSX parser
│   ├── upload.php                      # Multipart upload (CSV/XLSX, <=5MB)
│   ├── preview.php                     # Column mapping & row preview
│   ├── validate.php                    # Row diagnostics & auto-fix suggestions
│   ├── execute.php                     # Atomic execution & transaction rollback
│   └── get_history.php                 # Historical import logs
├── inventory/                          # Inventory Management module
│   ├── add_product.php                 # Add single product
│   ├── auth_helper.php                 # Shared owner authentication & scoping
│   ├── delete_product.php              # Delete product
│   ├── get_product_detail.php          # Detailed product view
│   ├── get_products.php                # List with search, filter, and sorting
│   ├── import_inventory.php            # Direct JSON batch import
│   └── update_product.php              # Update stock units & product fields
├── sales/                              # Sales & Invoicing Module
│   ├── add_sales_tables.sql            # Migration: sales and sales_items tables
│   ├── create.php                      # Create sale bill with atomic stock deduction
│   ├── list.php                        # Paginated sales list with single-query items
│   ├── summary.php                     # Period totals, daily points, top sellers & hourly
│   └── import.php                      # All-or-nothing sales import (JSON/CSV)
├── reorder/                            # Intelligent Reorder & Purchase Order Module
│   ├── add_reorder_tables.sql          # Migration: purchase_orders and items tables
│   ├── list.php                        # Rule-based recommendations with on-order subtractions
│   ├── create_order.php                # Place purchase order (PO-YYYY-NNN)
│   ├── orders.php                      # List saved purchase orders with items
│   ├── receive.php                     # Mark received and atomically increment stock
│   └── cancel.php                      # Cancel open purchase orders
├── insights/                           # AI Recommendations & Market Basket Insights Module
│   ├── add_insights_tables.sql         # Migration: recommendation_responses and idx_sale_product
│   ├── slow_moving.php                 # Slow-moving clearance recommendations with cost checks
│   ├── product_pairs.php               # Basket analysis (90-day window, lift > 1.0, paired items)
│   ├── respond.php                     # Upsert recommendation decision (accepted/dismissed)
│   └── history.php                     # Audit trail of recommendation decisions
├── uploads/
│   └── temp/                           # Temporary spreadsheet storage during import
├── sample_valid_import.csv             # Sample valid CSV file
├── sample_error_import.csv             # Sample CSV containing validation errors
├── sample_stock_oct15.xlsx             # Sample Excel XLSX file
├── test_insights_backend.php           # Automated test runner for insights module (38 tests)
├── test_reorder_backend.php            # Automated test runner for reorder module (29 tests)
├── test_sales_backend.php              # Automated test runner for sales module (37 tests)
├── test_token_auth.php                 # Automated test runner for token authentication
├── stocksense.sql                      # Complete DB schema (including recommendation_responses)
├── StockSense_Postman_Collection.json  # Complete Postman Collection
├── CHANGES.md                          # Changelog of additions and fixes
└── README.md
```

## Import Module Workflow
1. **Upload (`POST /import/upload.php`)**: Upload `.csv` or `.xlsx` file ($\le 5\text{ MB}$). Generates `file_token`.
2. **Preview (`POST /import/preview.php`)**: Maps spreadsheet columns to product fields (`sku`, `name`, `units`, `cost_price`, `price`) and displays table preview.
3. **Validate (`POST /import/validate.php`)**: Validates every row, flags formatting issues (e.g. `18O` instead of `18`), and provides auto-fixes.
4. **Execute (`POST /import/execute.php`)**: Executes stock receipt inside an atomic PDO transaction. Existing SKUs gain units (`units = units + added_units`), new SKUs create products. Rollback on failure.
5. **History (`GET /import/get_history.php`)**: Retrieves import history records for the authenticated shop owner.

## Real Token Authentication (Bearer Token)
All protected endpoints require a cryptographically secure Bearer token:
- **Login (`POST /auth/login.php`)**: Generates a random 64-character token via `bin2hex(random_bytes(32))`, computes its SHA-256 hash, and stores the hash in the `tokens` table with a 7-day expiration (`expires_at`). The plaintext token is returned to the client.
- **Verification (`authenticateShopOwner()` in `inventory/auth_helper.php`)**:
  - Reads `Authorization: Bearer <token>`.
  - Computes the SHA-256 hash of the received token.
  - Queries `tokens` joined with `owner_account` where `token_hash = ? AND expires_at > NOW()`.
  - Raw user IDs (`Bearer 2`) and email addresses are strictly rejected with `HTTP 401 Unauthorized`.
- **Logout (`POST /auth/logout.php`)**: Revokes the active session by deleting the matching `token_hash` row from `tokens`. Subsequent requests with that token return `HTTP 401 Unauthorized`.
- **Postman Automation**: The `Login` request in the Postman collection automatically extracts `response.token` and populates the `{{token}}` collection variable.