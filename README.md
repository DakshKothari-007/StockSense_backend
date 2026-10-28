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
├── uploads/
│   └── temp/                           # Temporary spreadsheet storage during import
├── sample_valid_import.csv             # Sample valid CSV file
├── sample_error_import.csv             # Sample CSV containing validation errors
├── sample_stock_oct15.xlsx             # Sample Excel XLSX file
├── stocksense.sql                      # Complete DB schema (owner_account, products, user_settings, import_history)
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

## Authentication
Every endpoint requires authentication. Supports:
- `Authorization: Bearer <token_or_user_id_or_email>`
- Custom Headers: `X-User-Id` or `X-User-Email`
- Query parameters / JSON body fallback: `user_id` or `email`