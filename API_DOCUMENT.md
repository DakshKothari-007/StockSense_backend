# StockSense REST API Specification

This document provides the complete API specification for the StockSense backend.

## 1. Global Architecture & Standards

### Base URL
```
http://<host>/StockSense_backend
```
In local development with XAMPP:
`http://localhost/StockSense_backend` or `http://10.0.2.2/StockSense_backend` (Android Emulator).

### Authentication
All protected endpoints require an `Authorization` HTTP header with a 64-character hexadecimal Bearer token:
```http
Authorization: Bearer <64_character_hex_token>
```
- Tokens are issued upon successful login via `POST /auth/login.php`.
- Protected endpoints hash the Bearer token with SHA-256 and look up active sessions with `expires_at > NOW()`.
- If missing, invalid, or expired, the API returns `HTTP 401 Unauthorized`.
- Multi-tenant data isolation is strictly enforced: `shop_owner_id` is always extracted from the authenticated token session and never trusted from client parameters.

### Response Format
All responses adhere to the standard JSON envelope:
```json
{
  "status": true,
  "message": "Human readable status message",
  "data": { ... }
}
```
*(Legacy auth endpoints also include `"success": true/false` for backwards compatibility).*

### HTTP Status Codes
- `200 OK`: Request succeeded.
- `201 Created`: Resource created successfully (e.g. sale bill, purchase order, signup).
- `400 Bad Request`: Validation failure, missing required field, bad types, or rule violation.
- `401 Unauthorized`: Missing or invalid Bearer token / wrong credentials.
- `403 Forbidden`: Access denied (e.g., trying to directly access scripts/schemas from the browser).
- `404 Not Found`: Target resource not found or belongs to another store.
- `405 Method Not Allowed`: Incorrect HTTP method used (e.g. GET instead of POST).
- `422 Unprocessable Entity`: Semantic validation failure (e.g., invalid email format during signup).
- `429 Too Many Requests`: Rate limit exceeded (5 failed login attempts in 15 minutes).
- `500 Internal Server Error`: Server failure (technical details logged to server `error_log`, generic error message returned to client

---

## 2. Module Endpoints

---

### Module 1: Authentication (`auth/`)

#### 1.1 Sign Up
- **Method & Path**: `POST /auth/signup.php`
- **Token Required**: No
- **App Screen**: `SignUpScreen.kt`
- **Request Body** (`application/json`):
  ```json
  {
    "full_name": "string (required, max 100)",
    "shop_name": "string (required, max 150)",
    "email": "string (required, valid email, max 150)",
    "password": "string (required, min 6 characters)"
  }
  ```
- **Success Response (`201 Created`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "Owner registered successfully.",
    "data": {
      "user_id": 5,
      "full_name": "Aarav Patel",
      "shop_name": "Patel Groceries",
      "email": "aarav@example.com"
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Missing fields or invalid JSON.
  - `409 Conflict`: Email is already registered.
  - `422 Unprocessable Entity`: Password too short (< 6 chars) or invalid email.

#### 1.2 Login
- **Method & Path**: `POST /auth/login.php`
- **Token Required**: No (Rate limited: 5 failed attempts per email/IP per 15 min)
- **App Screen**: `LoginScreen.kt`
- **Request Body** (`application/json`):
  ```json
  {
    "email": "string (required, valid email)",
    "password": "string (required)"
  }
  ```
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "Login successful.",
    "token": "4a7c81d2e9b01f3a5c7890abcdef1234567890abcdef1234567890abcdef1234",
    "expires_at": "2026-11-05 14:30:00",
    "user": {
      "id": 2,
      "full_name": "Ananya Rao",
      "shop_name": "Fresh Mart",
      "location": "Indiranagar, Bengaluru",
      "store_type": "Neighbourhood supermarket",
      "currency": "₹ INR / IST",
      "email": "ananya@example.com"
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Invalid JSON.
  - `401 Unauthorized`: Invalid email or password (returns remaining attempts).
  - `422 Unprocessable Entity`: Missing email or password format.
  - `429 Too Many Requests`: Account or IP temporarily locked after 5 failed attempts.

#### 1.3 Logout
- **Method & Path**: `POST /auth/logout.php`
- **Token Required**: Yes
- **App Screen**: `ProfileScreen.kt`
- **Request Body**: None (`{}`)
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "Logged out successfully."
  }
  ```
- **Error Responses**: `401 Unauthorized`

#### 1.4 Forgot Password
- **Method & Path**: `POST /auth/forgot_password.php`
- **Token Required**: No (Rate limited: 5 requests per email/IP per 15 min, HTTP 429)
- **App Screen**: `ForgotPasswordScreen.kt`
- **Request Body**:
  ```json
  {
    "email": "owner@example.com"
  }
  ```
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "If this email is registered, password reset instructions have been processed."
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Missing email or invalid JSON.
  - `404 Not Found`: Email not found in store registry.
  - `422 Unprocessable Entity`: Invalid email format.
  - `429 Too Many Requests`: Rate limit exceeded.

#### 1.5 Reset Password
- **Method & Path**: `POST /auth/reset_password.php`
- **Token Required**: No (Rate limited: 5 requests per email/IP per 15 min, HTTP 429)
- **App Screen**: `ResetPasswordScreen.kt`
- **Request Body**:
  ```json
  {
    "email": "owner@example.com",
    "new_password": "NewSecurePassword123!",
    "confirm_password": "NewSecurePassword123!"
  }
  ```
- **Behavior**:
  - Validates email exists in `owner_account`.
  - Verifies passwords match and length is at least 8 characters.
  - Updates password hash atomically in a single transaction.
  - **Revokes ALL existing active session tokens** for that user (logging out all active devices/sessions).
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "Password updated successfully. All previous sessions have been logged out."
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Missing fields or invalid JSON.
  - `404 Not Found`: Email not found.
  - `422 Unprocessable Entity`: Passwords do not match or length < 8 chars.
  - `429 Too Many Requests`: Rate limit exceeded.

#### 1.6 Delete Account
- **Method & Path**: `POST /auth/delete_account.php`
- **Token Required**: Yes (Rate limited on wrong password attempts)
- **App Screen**: Account deletion dialog / Settings
- **Request Body**:
  ```json
  {
    "current_password": "ownerpassword",
    "confirm_text": "DELETE"
  }
  ```
- **Behavior**:
  - Verifies current password against stored hash.
  - Requires `confirm_text` to strictly equal `"DELETE"`.
  - Runs in an atomic transaction deleting all associated store rows in strict cascade order (`sales_items` -> `sales` -> `purchase_order_items` -> `purchase_orders` -> `products` -> `import_history` -> `recommendation_responses` -> `notification_states` -> `user_settings` -> `tokens` -> `rate_limits` -> `owner_account`).
  - Leaves zero orphan rows.
  - Revokes all tokens immediately so subsequent calls return 401.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Account and all associated store data deleted permanently.",
    "data": null
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Missing/incorrect current password or `confirm_text` not "DELETE".
  - `401 Unauthorized`: Missing or invalid Bearer token.
  - `429 Too Many Requests`: Rate limit exceeded on failed attempts.

---

### Module 2: Profile (`auth/`)

#### 2.1 Get Profile
- **Method & Path**: `GET /auth/profile.php`
- **Token Required**: Yes
- **App Screen**: `ProfileScreen.kt`
- **Behavior**: Retrieves real store and owner profile information for the authenticated user session. Never uses demo or hardcoded values.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "Profile retrieved successfully.",
    "user": {
      "id": 2,
      "full_name": "Ananya Rao",
      "shop_name": "Fresh Mart",
      "location": "Indiranagar, Bengaluru",
      "store_type": "Neighbourhood supermarket",
      "currency": "₹ INR / IST",
      "email": "ananya@example.com",
      "store_id": "GB-BLR-002",
      "role": "Owner",
      "plan": "StockSense Starter",
      "data_snapshot": "2026-10-30 08:00:00"
    }
  }
  ```
- **Error Responses**:
  - `401 Unauthorized`: Missing or expired Bearer token.
  - `404 Not Found`: Account record not found.

#### 2.2 Update Profile
- **Method & Path**: `POST /auth/update_profile.php`
- **Token Required**: Yes
- **App Screen**: `ProfileScreen.kt` (Edit Profile Info Dialog)
- **Request Body** (`application/json`):
  ```json
  {
    "full_name": "string (required, max 100)",
    "email": "string (required, valid email, max 150)"
  }
  ```
- **Validation Rules**:
  - `full_name` cannot be blank and max length is 100 characters.
  - `email` must be a valid email format.
  - `email` must be unique across `owner_account` (excluding the current user).
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "Profile updated successfully.",
    "user": {
      "id": 2,
      "full_name": "Ananya Sharma",
      "shop_name": "Fresh Mart",
      "location": "Indiranagar, Bengaluru",
      "store_type": "Neighbourhood supermarket",
      "currency": "₹ INR / IST",
      "email": "ananya.new@example.com",
      "store_id": "GB-BLR-002",
      "role": "Owner",
      "plan": "StockSense Starter",
      "data_snapshot": "2026-10-30 08:15:00"
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Empty name or invalid JSON.
  - `401 Unauthorized`: Missing or invalid Bearer token.
  - `409 Conflict`: Email is already in use by another account (`"Email is already in use by another account."`).
  - `422 Unprocessable Entity`: Invalid email format.

#### 2.3 Update Shop Details
- **Method & Path**: `POST /auth/update_shop.php`
- **Token Required**: Yes
- **App Screen**: `ProfileScreen.kt` (Edit Shop Dialog)
- **Request Body** (`application/json`):
  ```json
  {
    "shop_name": "string (required, max 150)",
    "location": "string (optional, max 255)",
    "store_type": "string (required, enum: 'Neighbourhood supermarket' | 'Medium supermarket' | 'Grocery / Kirana store' | 'Other')"
  }
  ```
- **Validation Rules**:
  - `shop_name` cannot be blank and max length is 150 characters.
  - `location` max length is 255 characters.
  - `store_type` must match one of the exact allowed values:
    1. `"Neighbourhood supermarket"`
    2. `"Medium supermarket"`
    3. `"Grocery / Kirana store"`
    4. `"Other"`
  - Note: `currency` is read-only text in the app and cannot be altered via this endpoint.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "success": true,
    "message": "Store details updated successfully.",
    "user": {
      "id": 2,
      "full_name": "Ananya Sharma",
      "shop_name": "Fresh Mart Superstore",
      "location": "Indiranagar, Bengaluru",
      "store_type": "Medium supermarket",
      "currency": "₹ INR / IST",
      "email": "ananya.new@example.com",
      "store_id": "GB-BLR-002",
      "role": "Owner",
      "plan": "StockSense Starter",
      "data_snapshot": "2026-10-30 08:20:00"
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Blank `shop_name` or invalid JSON.
  - `401 Unauthorized`: Missing or invalid Bearer token.
  - `422 Unprocessable Entity`: Location exceeds 255 characters or `store_type` is not in the allowed list (`"Invalid store type. Allowed: Neighbourhood supermarket, Medium supermarket, Grocery / Kirana store, Other"`).

---

### Module 3: Inventory (`inventory/`)

#### 3.1 Get Products List
- **Method & Path**: `GET /inventory/get_products.php`
- **Token Required**: Yes
- **App Screen**: `InventoryScreen.kt`
- **Query Parameters**:
  - `search`: string (optional SKU or name search)
  - `category`: string (optional category filter)
  - `status`: string (`in_stock`, `low_stock`, `out_of_stock`, `slow_moving`)
  - `sort`: string (`name_asc`, `name_desc`, `stock_asc`, `stock_desc`, `price_asc`, `price_desc`)
  - `page`: int (default 1)
  - `limit`: int (default 50)
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Products fetched successfully.",
    "data": {
      "products": [
        {
          "id": 1,
          "sku": "AMUL-MILK-500",
          "name": "Amul Taaza Milk 500ml",
          "category": "Dairy",
          "units": 8,
          "price": 27.00,
          "cost_price": 23.50,
          "min_stock_threshold": 24,
          "status": "low_stock"
        }
      ],
      "total_count": 42
    }
  }
  ```

#### 3.2 Get Product Detail
- **Method & Path**: `GET /inventory/get_product_detail.php?id={id}`
- **Token Required**: Yes
- **App Screen**: `ProductDetailScreen.kt`, `ProductBiscuitsScreen.kt`
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Product details fetched.",
    "data": {
      "id": 1,
      "sku": "AMUL-MILK-500",
      "name": "Amul Taaza Milk 500ml",
      "category": "Dairy",
      "units": 8,
      "price": 27.00,
      "cost_price": 23.50,
      "min_stock_threshold": 24,
      "status": "low_stock",
      "stock_cover_days": 1.6
    }
  }
  ```

#### 3.3 Add Product
- **Method & Path**: `POST /inventory/add_product.php`
- **Token Required**: Yes
- **App Screen**: `AddProductScreen.kt`
- **Request Body**:
  ```json
  {
    "name": "string (required, max 150 characters)",
    "sku": "string (optional, max 50 characters; if omitted or empty, server generates a unique SKU in format SKU-{PREFIX}-{HASH} where PREFIX is 3-4 uppercase alphanumeric characters derived from category or product name and HASH is a 5-character short unique hash. Reuses the import helper ColumnMatcher; regenerates up to 10 attempts on collision in the shop)",
    "category": "string (optional, default 'General')",
    "description": "string (optional)",
    "units": 100,
    "price": 25.00,
    "cost_price": 20.00,
    "min_stock_threshold": 15,
    "icon_type": 2
  }
  ```
- **Success Response (`201 Created`)**:
  ```json
  {
    "status": true,
    "message": "Product added to inventory successfully.",
    "data": {
      "id": "1",
      "name": "Fortune Sunflower Oil · 1 L",
      "sku": "SKU-FORT-A1B2C",
      "category": "Staples",
      "price": "₹145",
      "raw_price": 145.0,
      "cost_price": "₹120",
      "raw_cost_price": 120.0,
      "units": "50",
      "min_stock_threshold": 15
    }
  }
  ```
- **Error Responses**:
  - `409 Conflict`: 
    - Client-supplied SKU already exists in this shop: `{"status": false, "message": "A product with this SKU already exists.", "data": {}}`.
    - Auto-generation could not find a unique SKU after 10 attempts: `{"status": false, "message": "Unable to generate a unique SKU after 10 attempts. Please specify a SKU manually.", "data": {}}`.
    - Note: Uniqueness is strictly scoped per shop (`shop_owner_id`); the same SKU in another shop never conflicts.
  - `422 Unprocessable Entity`: Missing required name, negative price/units.

#### 3.4 Update Product
- **Method & Path**: `POST /inventory/update_product.php`
- **Token Required**: Yes
- **App Screen**: `ProductDetailScreen.kt`
- **Request Body**:
  ```json
  {
    "id": 1,
    "units": 50,
    "price": 28.00,
    "cost_price": 24.00,
    "min_stock_threshold": 20
  }
  ```
- **Success Response (`200 OK`)**

#### 3.5 Delete Product
- **Method & Path**: `POST /inventory/delete_product.php`
- **Token Required**: Yes
- **App Screen**: `EditProductScreen.kt`
- **Request Body**:
  ```json
  {
    "id": 1,
    "sku": "AMUL-MILK-500"
  }
  ```
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Product deleted.",
    "data": {
      "deleted_id": 1,
      "deleted_sku": "AMUL-MILK-500"
    }
  }
  ```
- **Error Responses**:
  - `404 Not Found`: Product not found in the authenticated shop's inventory:
    ```json
    {
      "status": false,
      "message": "Product not found.",
      "data": {}
    }
    ```
  - `409 Conflict` (Sales History): Product cannot be deleted because it has sales history:
    ```json
    {
      "status": false,
      "message": "This product has sales history, so it cannot be deleted. You can set its stock to 0 or mark it inactive.",
      "data": {
        "product_id": 1,
        "sales_count": 5
      }
    }
    ```
  - `409 Conflict` (Open Purchase Order): Product is referenced in an open purchase order (`status = 'placed'`):
    ```json
    {
      "status": false,
      "message": "This product is in an open purchase order.",
      "data": {
        "product_id": 1,
        "open_po_count": 1
      }
    }
    ```
  - `422 Unprocessable Entity`: Missing both `id` and `sku` in request payload.

---

### Module 4: Spreadsheet Bulk Import (`import/`)

Header detection is case-insensitive, ignores spaces, dashes, and underscores, supports fuzzy matching (normalized similarity $\ge 0.70$) for typos/abbreviations, inspects the first 20 sample rows for data type heuristics (integers, decimals, short codes, text), and supports per-shop persistent header mappings (`import_mappings` table).

The backend supports the following canonical fields and extended supermarket synonyms:
- `name` *(Required)*: `product name`, `item name`, `item description`, `product description`, `product`, `item`, `name`, `description`, `title`, `particulars`, `article name`, `goods description`, `product title`, `item title`, `item details`, `prod name`, `item desc`, `prod desc`, `descr`
- `units` *(Required)*: `quantity`, `qty`, `units`, `stock`, `opening stock`, `qty on hand`, `quantity on hand`, `stock in hand`, `closing stock`, `current stock`, `balance stock`, `balance`, `pcs`, `count`, `physical stock`, `units to add`, `add stock`, `available qty`, `on hand`, `qnty`, `stock qty`, `total stock`, `units in stock`
- `sku` *(Optional)*: `sku`, `code`, `item code`, `product code`, `barcode`, `ean`, `ean code`, `hsn`, `hsn code`, `upc`, `article no`, `article number`, `item id`, `gtin`, `sku code`, `product id`, `item no`, `part no`, `bar code`, `itemcode`, `prodcode`. *(If omitted or unmapped, the backend uses any detected barcode column, otherwise generates a deterministic stable SKU: `SKU-{PREFIX}-{HASH}`)*
- `price` *(Optional)*: `selling price`, `sale price`, `price`, `mrp`, `rate`, `selling rate`, `sale rate`, `unit price`, `retail price`, `sp`, `sales price`, `sales rate`, `selling`, `retail rate`, `unit rate`, `item price`, `sale mrp`, `mrp rate`. *(Defaults to `cost_price` or `0.00`)*
- `cost_price` *(Optional)*: `cost price`, `cost`, `purchase price`, `buying price`, `purchase rate`, `buy rate`, `unit cost`, `cp`, `landed cost`, `cost rate`, `purchase`, `base cost`, `buy price`, `purchase cost`, `item cost`. *(Defaults to `0.00`)*
- `category` *(Optional)*: `category`, `type`, `group`, `department`, `dept`, `product category`, `classification`, `family`, `section`, `item group`, `cat`, `sub category`, `sub cat`, `prod group`. *(Defaults to `General`)*
- `minimum_stock` *(Optional)*: `minimum stock`, `min stock`, `reorder level`, `threshold`, `min level`, `rop`, `safety stock`, `alert qty`, `low stock threshold`, `min qty`, `reorder point`, `min threshold`, `alert level`, `warning level`. *(Defaults to `10`)*

Validation Rule: Only `name` and `units` (quantity) are strictly required to be mapped. If either is not mapped, `validate.php` and `execute.php` reject the request with `400 Bad Request` ("Validation failed: ... column(s) are required and must be mapped."). Unknown extra columns in the spreadsheet are safely ignored.

#### 4.1 Upload Spreadsheet
- **Method & Path**: `POST /import/upload.php`
- **Token Required**: Yes
- **App Screen**: `ImportInventoryUploadScreen.kt`
- **Request**: `multipart/form-data` with `file` (`.csv` or `.xlsx`, $\le 5\text{MB}$)
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "File uploaded and parsed successfully",
    "data": {
      "file_token": "imp_2_abc123_1793164882.csv",
      "file_name": "stock_oct.xlsx",
      "file_size": "15 KB",
      "file_size_bytes": 15234,
      "total_rows_detected": 5,
      "headers": ["Product Name", "SKU", "Category", "Quantity", "Cost Price", "Selling Price", "Minimum Stock"],
      "suggested_mapping": {
        "name": "Product Name",
        "sku": "SKU",
        "category": "Category",
        "units": "Quantity",
        "cost_price": "Cost Price",
        "price": "Selling Price",
        "minimum_stock": "Minimum Stock"
      },
      "confidence": {
        "name": 1.0,
        "sku": 1.0,
        "category": 1.0,
        "units": 1.0,
        "cost_price": 1.0,
        "price": 1.0,
        "minimum_stock": 1.0
      },
      "matched_count": 7,
      "total_columns": 7,
      "required_matched": true,
      "is_saved": false
    }
  }
  ```

#### 4.2 Preview Columns & Rows
- **Method & Path**: `POST /import/preview.php`
- **Token Required**: Yes
- **App Screen**: `ImportInventoryPreviewScreen.kt`
- **Request Body**:
  ```json
  {
    "file_token": "imp_2_abc123_1793164882.csv",
    "column_mapping": {
      "name": "Product Name",
      "sku": "SKU",
      "category": "Category",
      "units": "Quantity",
      "cost_price": "Cost Price",
      "price": "Selling Price",
      "minimum_stock": "Minimum Stock"
    }
  }
  ```
- **Success Response (`200 OK`)**: Returns `matched_count`, `total_columns`, `required_matched`, `is_saved`, `confidence`, `mappings`, `available_headers`, `total_rows`, and parsed `preview_rows`.

#### 4.3 Validate Spreadsheet Data
- **Method & Path**: `POST /import/validate.php`
- **Token Required**: Yes
- **App Screen**: `ImportInventoryValidationScreen.kt`
- **Request Body**:
  ```json
  {
    "file_token": "imp_2_abc123_1793164882.csv",
    "column_mapping": {
      "name": "Product Name",
      "sku": "SKU",
      "category": "Category",
      "units": "Quantity",
      "cost_price": "Cost Price",
      "price": "Selling Price",
      "minimum_stock": "Minimum Stock"
    },
    "corrections": [
      {
        "row_number": 4,
        "field": "units",
        "value": 18
      }
    ]
  }
  ```
- **Validation Rule**: If `name` or `units` is unmapped or empty, returns `400 Bad Request`. SKU is optional and will fall back to barcode or a stable generated SKU. Safe defaults are assigned for category (`General`), cost price (`0.00`), selling price (`cost_price` or `0.00`), and minimum stock (`10`).
- **Success Response (`200 OK`)**: Returns `rows_checked`, `ready_rows`, `errors_remaining`, `ready_to_import`, row-level `errors` array, and `summary`.

#### 4.4 Execute Import
- **Method & Path**: `POST /import/execute.php`
- **Token Required**: Yes
- **App Screen**: `ImportInventoryReadyScreen.kt`, `InventoryImportedScreen.kt`
- **Headers**:
  - `Authorization: Bearer <token>`
  - `Idempotency-Key: <unique_attempt_id>` *(Optional but recommended to prevent double-import on network retries)*
- **Request Body**:
  ```json
  {
    "file_token": "imp_2_abc123_1793164882.csv",
    "file_name": "stock_oct.xlsx",
    "idempotency_key": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
    "column_mapping": {
      "name": "Product Name",
      "sku": "SKU",
      "category": "Category",
      "units": "Quantity",
      "cost_price": "Cost Price",
      "price": "Selling Price",
      "minimum_stock": "Minimum Stock"
    },
    "confirm_replace": false
  }
  ```
  *(Note: If the store's `import_mode` is set to `REPLACE`, `confirm_replace: true` is strictly required. If an `idempotency_key` has already been executed for this store, the server replays the previous 200 OK response without adding stock again).*
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Your shelves are up to date. Import complete.",
    "data": {
      "import_id": 1,
      "file_name": "stock_oct.xlsx",
      "products_updated": 4,
      "products_added": 1,
      "data_rows_imported": "5 of 5",
      "stock_added": "134 units",
      "corrected_skipped_text": "1 / 0 rows",
      "total_active_products": 129,
      "new_inventory_value": "₹1,56,212"
    }
  }
  ```

#### 4.5 Get Import History
- **Method & Path**: `GET /import/get_history.php`
- **Token Required**: Yes
- **App Screen**: `ImportInventoryUploadScreen.kt`, `InventoryScreen.kt`
- **Success Response (`200 OK`)**: Returns list of past imports, timestamps, and row counts.

---

### Module 5: Dashboard (`dashboard/`)

#### 5.1 Dashboard Summary
- **Method & Path**: `GET /dashboard/summary.php`
- **Token Required**: Yes
- **App Screen**: `DashboardHomeScreen.kt`, `MainScreen.kt`
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Dashboard summary retrieved successfully",
    "data": {
      "store_name": "Fresh Mart",
      "date": "15 Oct 2026",
      "metrics": {
        "today_sales": 18450.00,
        "today_bills_count": 96,
        "inventory_value": 312000.00,
        "active_products_count": 142
      },
      "alerts": {
        "low_stock": {
          "count": 1,
          "items": [
            {
              "id": 1,
              "name": "Amul Taaza Milk 500ml",
              "units": 8,
              "min_stock_threshold": 24
            }
          ]
        },
        "slow_moving": {
          "count": 1,
          "items": [
            {
              "id": 4,
              "name": "Marie Biscuits 200g",
              "units": 96,
              "sold_30_days": 6
            }
          ]
        }
      },
      "ai_priorities": {
        "reorder": { ... },
        "clearance": { ... },
        "bundle": { ... }
      },
      "sales_trend_7_days": [ ... ],
      "top_selling_products": [ ... ],
      "category_breakdown": [ ... ]
    }
  }
  ```

---

### Module 6: Sales & Invoicing (`sales/`)

#### 6.1 Create Sale Bill
- **Method & Path**: `POST /sales/create.php`
- **Token Required**: Yes
- **App Screen**: POS / Invoicing / Bill Generator
- **Request Body**:
  ```json
  {
    "bill_no": "BILL-2026-001 (required, unique per shop)",
    "sale_date": "2026-10-29 10:15:00 (optional, past/present only. If missing or empty, defaults to current server time in Asia/Kolkata Y-m-d H:i:s)",
    "customer_name": "Ramesh Kumar (optional)",
    "customer_phone": "9876543210 (optional)",
    "payment_method": "UPI | CASH | CARD (optional, default CASH)",
    "items": [
      {
        "product_id": 1,
        "quantity": 2
      }
    ]
  }
  ```
  *(Server computes line total and total amount using active product prices in the DB; deduplicates lines atomically; subtracts stock safely).*
- **Success Response (`201 Created`)**

#### 6.2 Get Sales List
- **Method & Path**: `GET /sales/list.php`
- **Token Required**: Yes
- **App Screen**: `SalesAnalyticsScreen.kt`
- **Query Parameters**: `start_date`, `end_date`, `page`, `limit`
- **Success Response (`200 OK`)**: Returns paginated list of bills with embedded line items.

#### 6.3 Sales Summary & Analytics
- **Method & Path**: `GET /sales/summary.php?period={today|7_days|30_days|90_days|this_month}`
- **Token Required**: Yes
- **App Screen**: `SalesAnalyticsScreen.kt`
- **Success Response (`200 OK`)**: Period totals, daily sales chart points, top selling products, and hourly breakdown.

#### 6.4 Import Sales History
- **Method & Path**: `POST /sales/import.php`
- **Token Required**: Yes
- **App Screen**: Sales CSV Import
- **Request Body**: JSON batch or CSV payload; optional `deduct_stock: false`.
- **Success Response (`201 Created`)**

#### 6.5 Product 7-Day Sales History
- **Method & Path**: `GET /sales/product_history.php?id={id}` or `GET /sales/product_history.php?sku={sku}`
- **Token Required**: Yes
- **App Screen**: `ProductDetailScreen.kt` ("Sales history" card)
- **Query Parameters**:
  - `id` (integer, optional if `sku` provided): Product ID
  - `sku` (string, optional if `id` provided): Product SKU
- **Error Responses**:
  - `401 Unauthorized`: Missing or invalid Bearer token.
  - `422 Unprocessable Entity`: Neither `id` nor `sku` was provided.
  - `404 Not Found`: Product not found in authenticated shop inventory.
- **Success Response (`200 OK`)**:
  - Returns 7 contiguous daily sales history data points up to today (`day_number`, `day_label`, `units_sold`, `height_ratio`, `is_today`), `total_units_sold`, and boolean flag `has_sales`. If zero sales in the past 7 days, `has_sales` is `false`.

---

### Module 7: Reorder & Purchase Orders (`reorder/`)

#### 7.1 Reorder Recommendations List
- **Method & Path**: `GET /reorder/list.php`
- **Token Required**: Yes
- **App Screen**: `ReorderRecommendationScreen.kt`
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Reorder recommendations fetched",
    "data": {
      "summary": { "critical_count": 1, "warning_count": 0 },
      "items": [
        {
          "product_id": 1,
          "name": "Amul Taaza Milk 500ml",
          "units": 8,
          "min_stock_threshold": 24,
          "on_order_units": 0,
          "suggested_quantity": 60,
          "estimated_cost": 1620.00,
          "priority": "HIGH",
          "priority_rule": "Out of stock or critically below minimum threshold",
          "reason": "Stock is critically low (8 left vs 24 min)."
        }
      ]
    }
  }
  ```

#### 7.2 Create Purchase Order
- **Method & Path**: `POST /reorder/create_order.php`
- **Token Required**: Yes
- **App Screen**: `ReorderRecommendationScreen.kt`, `ReorderPlacedScreen.kt`
- **Request Body**:
  ```json
  {
    "supplier_name": "Amul Dairy Dist",
    "items": [
      {
        "product_id": 1,
        "quantity": 60
      }
    ]
  }
  ```
  *(Server automatically assigns order number in format `PO-YYYY-NNN` per shop).*
- **Success Response (`201 Created`)**

#### 7.3 List Purchase Orders
- **Method & Path**: `GET /reorder/orders.php?status={placed|received|cancelled}`
- **Token Required**: Yes
- **App Screen**: `ReorderPlacedScreen.kt`
- **Success Response (`200 OK`)**

#### 7.4 Receive Purchase Order
- **Method & Path**: `POST /reorder/receive.php`
- **Token Required**: Yes
- **App Screen**: `ReorderPlacedScreen.kt`
- **Request Body**:
  ```json
  {
    "order_id": 12
  }
  ```
  *(Atomically transitions status from `placed` to `received` and increments product units).*
- **Success Response (`200 OK`)**

#### 7.5 Cancel Purchase Order
- **Method & Path**: `POST /reorder/cancel.php`
- **Token Required**: Yes
- **App Screen**: `ReorderPlacedScreen.kt`
- **Request Body**:
  ```json
  {
    "order_id": 12
  }
  ```
- **Success Response (`200 OK`)**

---

### Module 8: AI Insights & Basket Analysis (`insights/`)

#### 8.1 Slow-Moving Clearance Recommendations
- **Method & Path**: `GET /insights/slow_moving.php`
- **Token Required**: Yes
- **App Screen**: `SlowMovingRecommendationScreen.kt`
- **Query Parameters**: `include_dismissed`: boolean (default 0)
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Slow-moving recommendations fetched successfully",
    "data": {
      "has_enough_sales_history": true,
      "items": [
        {
          "product_id": 4,
          "name": "Marie Biscuits 200g",
          "units": 96,
          "units_sold_30_days": 6,
          "stock_cover_days": 480.0,
          "suggested_discount_percent": 10,
          "clearance_price": 27.00,
          "margin_status": "above cost"
        }
      ]
    }
  }
  ```

#### 8.2 Product Pair Basket Analysis
- **Method & Path**: `GET /insights/product_pairs.php`
- **Token Required**: Yes
- **App Screen**: `RecommendationPairsScreen.kt`
- **Query Parameters**: `min_support` (0.0 to 1.0), `min_lift` ($\ge 1.0$), `include_dismissed`
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Product pair recommendations fetched successfully",
    "data": {
      "has_enough_sales_history": true,
      "pairs": [
        {
          "pair_id": "pair_37_38",
          "product_1": { "id": 37, "name": "Tata Tea Gold 250g" },
          "product_2": { "id": 38, "name": "Parle-G 250g" },
          "headline": "Often bought together: Tata Tea Gold 250g + Parle-G 250g",
          "lift": 2.17,
          "support": 0.125
        }
      ]
    }
  }
  ```

#### 8.3 Respond to Recommendation
- **Method & Path**: `POST /insights/respond.php`
- **Token Required**: Yes
- **App Screen**: `AcceptedOverlayScreen.kt`, `DismissedOverlayScreen.kt`
- **Request Body**:
  ```json
  {
    "recommendation_type": "slow_moving | product_pair | reorder",
    "product_id_1": 37,
    "product_id_2": 38,
    "action": "accepted | dismissed",
    "notes": "Bundle discount created"
  }
  ```
- **Success Response (`200 OK`)**

#### 8.4 Recommendation History Audit
- **Method & Path**: `GET /insights/history.php?type={slow_moving|product_pair}`
- **Token Required**: Yes
- **App Screen**: Recommendations Audit History
- **Success Response (`200 OK`)**

---

### Module 9: Store & System Settings (`settings/`)

*(Note: Legacy paths `/auth/get_settings.php`, `/auth/update_settings.php`, and `/auth/change_password.php` are retained as transparent wrappers).*

#### 9.1 Get Settings
- **Method & Path**: `GET /settings/get.php`
- **Token Required**: Yes
- **App Screen**: `SettingsScreen.kt`
- **Behavior**: Read-only. Does not write to DB if row does not exist; returns system defaults with `is_customized: false`.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Settings retrieved successfully",
    "data": {
      "theme_mode": "SYSTEM",
      "notifications_enabled": true,
      "low_stock_alerts": true,
      "daily_summary": true,
      "currency_code": "INR",
      "currency_symbol": "₹",
      "lead_time_days": 2,
      "safety_days": 3,
      "cover_days": 7,
      "min_history_days": 30,
      "slow_moving_threshold_days": 60,
      "import_mode": "ADD",
      "timezone": "Asia/Kolkata",
      "is_customized": false
    }
  }
  ```

#### 9.2 Update Settings
- **Method & Path**: `POST /settings/update.php`
- **Token Required**: Yes
- **App Screen**: `SettingsScreen.kt`
- **Request Body** (`application/json`):
  ```json
  {
    "theme_mode": "SYSTEM | LIGHT | DARK",
    "notifications_enabled": true,
    "low_stock_alerts": true,
    "daily_summary": true,
    "lead_time_days": 5,
    "safety_days": 4,
    "cover_days": 14,
    "min_history_days": 45,
    "slow_moving_threshold_days": 90,
    "import_mode": "ADD | REPLACE"
  }
  ```
  *(Rules: Rejects empty bodies; strict integer/boolean types; rejects unknown keys; rejects timezone updates with 400 as read-only; email updates require `current_password` and uniqueness check).*
- **Success Response (`200 OK`)**
- **Error Responses**: `400 Bad Request`

#### 9.3 Change Password
- **Method & Path**: `POST /settings/change_password.php`
- **Token Required**: Yes
- **App Screen**: `SettingsScreen.kt`, `ProfileScreen.kt`
- **Request Body**:
  ```json
  {
    "current_password": "ownerpassword",
    "new_password": "NewStrongPassword123!"
  }
  ```
  *(Rules: Validates current password; locks after 5 consecutive failures for 15 minutes; enforces min 8 chars; requires new password to differ from current; revokes all other active sessions while preserving current session).*
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Password changed successfully. All other active sessions have been logged out.",
    "data": null
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Incorrect current password or new password invalid.
  - `429 Too Many Requests`: Account temporarily locked due to repeated incorrect password attempts.

#### 9.4 Reset Defaults
- **Method & Path**: `POST /settings/reset_defaults.php`
- **Token Required**: Yes
- **App Screen**: `SettingsScreen.kt`
- **Request Body**: `{}`
- **Success Response (`200 OK`)**: Restores all shop settings back to system defaults.

---

### Module 10: Notifications (`notifications/`)

Generated on-the-fly directly from live database state (no cron or background workers). Read/dismiss state is recorded in `notification_states`. When an alert condition clears (e.g., product restocked, product no longer slow-moving), stale saved states are automatically cleaned up.

#### 10.1 List Notifications
- **Method & Path**: `GET /notifications/list.php`
- **Token Required**: Yes
- **App Screen**: `NotificationsScreen.kt`
- **Query Parameters**:
  - `type` (string, optional): Filter by `low_stock`, `slow_moving`, `sales`, `import`.
  - `unread_only` (bool, optional, default false): Only return unread items.
  - `limit` (int, optional, default 50, max 50): Number of notifications per page.
  - `page` (int, optional, default 1): Page number.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Notifications retrieved successfully",
    "data": {
      "unread_count": 3,
      "total_count": 3,
      "page": 1,
      "limit": 50,
      "items": [
        {
          "key": "low_stock:1",
          "type": "low_stock",
          "title": "Amul Milk · 500 ml is running low",
          "body": "Only 8 units left. AI suggests reordering 32 units for ₹864.00.",
          "numbers_used": {
            "units": 8,
            "min_stock_threshold": 24,
            "suggested_quantity": 32,
            "estimated_cost": 864.00
          },
          "created_for_date": "2026-10-29",
          "product_id": 1,
          "priority": 1,
          "is_read": false,
          "read_at": null
        },
        {
          "key": "slow_moving:2",
          "type": "slow_moving",
          "title": "Britannia Marie · 150 g needs attention",
          "body": "96 packs in stock, just 0 sold in 30 days. Review a clearance or bundle offer.",
          "numbers_used": {
            "units": 96,
            "units_sold_30_days": 0,
            "stock_cover_days": null
          },
          "created_for_date": "2026-10-29",
          "product_id": 2,
          "priority": 2,
          "is_read": false,
          "read_at": null
        },
        {
          "key": "sales:2026-10-29",
          "type": "sales",
          "title": "Sales are up 15% so far today",
          "body": "₹4,200.00 in sales from 12 bills so far today. Yesterday up to this time was ₹3,650.00.",
          "numbers_used": {
            "today_sales": 4200.00,
            "today_bills": 12,
            "yesterday_comparable_sales": 3650.00,
            "yesterday_comparable_bills": 10,
            "percentage_change": 15
          },
          "created_for_date": "2026-10-29",
          "product_id": null,
          "priority": 3,
          "is_read": false,
          "read_at": null
        }
      ]
    }
  }
  ```

#### 10.2 Get Unread Count
- **Method & Path**: `GET /notifications/unread_count.php`
- **Token Required**: Yes
- **App Screen**: Notification bell badge on `DashboardHomeScreen.kt`
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Unread notification count retrieved",
    "data": {
      "unread_count": 3
    }
  }
  ```

#### 10.3 Mark Single Notification Read
- **Method & Path**: `POST /notifications/mark_read.php`
- **Token Required**: Yes
- **App Screen**: `NotificationsScreen.kt` (tapping on a card)
- **Request Body**:
  ```json
  {
    "key": "low_stock:1"
  }
  ```
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Notification marked as read",
    "data": {
      "key": "low_stock:1",
      "is_read": true
    }
  }
  ```

#### 10.4 Mark All Notifications Read
- **Method & Path**: `POST /notifications/mark_all_read.php`
- **Token Required**: Yes
- **App Screen**: `NotificationsScreen.kt` ("Mark all as read" button)
- **Request Body**: `{}`
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "All notifications marked as read",
    "data": {
      "marked_count": 3
    }
  }
  ```

#### 10.5 Dismiss Notification
- **Method & Path**: `POST /notifications/dismiss.php`
- **Token Required**: Yes
- **App Screen**: `NotificationsScreen.kt` (swipe to dismiss / close)
- **Request Body**:
  ```json
  {
    "key": "slow_moving:2"
  }
  ```
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Notification dismissed",
    "data": {
      "key": "slow_moving:2",
      "dismissed": true
    }
  }
  ```

#### 10.6 Register Device (FCM Token)
- **Method & Path**: `POST /notifications/register_device.php`
- **Token Required**: Yes (Bearer Token)
- **App Screen**: App Launch / FCM Service Token Refresh
- **Request Body**:
  ```json
  {
    "fcm_token": "eY123abc...",
    "platform": "android"
  }
  ```
- **Validation**:
  - `fcm_token` (string, required): Up to 255 characters.
  - `platform` (string, optional, default "android").
- **Behavior**:
  - Inserts or updates device token for the authenticated store owner.
  - If token was previously registered to another shop/user, safely reassigns it to the current shop owner.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Device token registered successfully.",
    "data": {
      "shop_id": 2,
      "fcm_token": "eY123abc...",
      "platform": "android"
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Missing or invalid `fcm_token`.
  - `401 Unauthorized`: Missing or invalid Bearer token.

#### 10.7 Unregister Device (FCM Token)
- **Method & Path**: `POST /notifications/unregister_device.php`
- **Token Required**: Yes (Bearer Token)
- **App Screen**: Sign Out / Disable Push
- **Request Body**:
  ```json
  {
    "fcm_token": "eY123abc..."
  }
  ```
- **Behavior**:
  - Removes the specific device token for the authenticated store owner.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Device token unregistered successfully.",
    "data": null
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Missing `fcm_token`.
  - `401 Unauthorized`: Missing or invalid Bearer token.

#### 10.8 Push Notification System (FCM HTTP v1) Rules & Triggers
StockSense uses Firebase Cloud Messaging (FCM) HTTP v1 protocol using pure PHP (RS256 JWT generation with `openssl_sign` and Google OAuth2 access token caching). Push operations run post-commit and never block or fail database transactions.

- **Configuration & Security**:
  - Service Account Key is located at `C:\xampp\StockSense_private\firebase-service-account.json` (outside the web root).
  - Web requests to `cron/`, `includes/`, and test scripts are blocked by Apache `.htaccess`.
  - Missing service account key fails silently with a single log line and never crashes callers.

- **Device Token Lifecycle**:
  - Registered via `POST /notifications/register_device.php`. If a token exists under another shop, it is safely moved to the authenticated shop.
  - Removed via `POST /notifications/unregister_device.php`.
  - Removed on logout if `fcm_token` is included in `POST /auth/logout.php`.
  - All tokens and push logs purged on account deletion in `POST /auth/delete_account.php`.
  - Invalid/expired tokens returning `UNREGISTERED` or `INVALID_ARGUMENT` from FCM HTTP v1 API are automatically deleted from `device_tokens`.

- **Settings Toggles Enforcement (from `get_shop_settings()` in `user_settings`)**:
  - `low_stock_alerts`: Controls `low_stock` push notifications.
  - `ai_recommendations`: Controls `reorder_suggestion` (and AI insights) push notifications.
  - `daily_sales_summary`: Controls `daily_summary` push notifications.
  - `weekly_email_digest`: No push notifications sent.

- **Allowed Push Notification Types**:
  1. **Low Stock Alert (`low_stock`)**:
     - *Title*: `"Low stock"`
     - *Body*: `"{Product Name} has {units} units left."`
     - *Channel*: `stocksense_alerts` (Priority: High)
     - *Data Payload*: `{"type": "low_stock", "product_id": "{id}"}`
     - *Trigger*: After sales recording (`sales/create.php`) or inventory import (`import/execute.php`) when `units <= min_stock_threshold`.
     - *Settings Toggle*: `low_stock_alerts` must be `1`.
     - *Deduplication*: `low_stock:{product_id}:{YYYY-MM-DD}` (maximum 1 push per product per day).
  2. **Reorder Suggestion / AI Recommendation (`reorder_suggestion`)**:
     - *Title*: `"Reorder suggestion"`
     - *Body*: `"AI suggests reordering {qty} units of {Product Name} ({₹cost})."`
     - *Channel*: `stocksense_alerts` (Priority: High)
     - *Data Payload*: `{"type": "reorder_suggestion", "product_id": "{id}"}`
     - *Trigger*: Reorder recommendations and AI inventory insights.
     - *Settings Toggle*: `ai_recommendations` must be `1`.
     - *Deduplication*: `reorder_suggestion:{product_id}:{YYYY-MM-DD}` (maximum 1 push per product per day).
  3. **Daily Sales Summary (`daily_summary`)**:
     - *Title*: `"Daily summary"`
     - *Body*: `"Yesterday's sales: ₹{sales_amount}. {low_stock_count} products low on stock."`
     - *Channel*: `stocksense_alerts` (Priority: High)
     - *Data Payload*: `{"type": "daily_summary", "date": "{YYYY-MM-DD}", "sales_amount": "{amount}", "low_stock_count": "{count}"}`
     - *Trigger*: Daily cron job (`cron/daily_summary.php` / `cron/daily_summary.bat`).
     - *Settings Toggle*: `daily_sales_summary` must be `1`.
     - *Deduplication*: `daily_summary:{shop_id}:{YYYY-MM-DD}` (maximum 1 push per shop per day).

- **Strictly Excluded Events (No Push Notifications)**:
  - `order_placed`: No push notification sent.
  - `order_received`: No push notification sent.
  - `import_done`: No push notification sent.
  - `weekly_email_digest`: No push notification or email sender.
  *(These events update in-app database records only; `PushNotifier::send()` explicitly blocks them with reason `event_type_not_allowed_for_push`).*

- **Number Formatting**:
  - Currency values are formatted using Indian number grouping (e.g., `₹1,480`, `₹1,20,500`, `₹1,00,00,000`).

---

### Module 11: AI Assistant ("Dukaan Mitra") (`ai/`)

Dukaan Mitra is the app's built-in intelligent AI assistant for shop owners. It operates via secure, read-only function calling directly querying shop data and providing practical retail guidance and general assistance.

#### Key Security & Configuration Principles
- **Dual Model Provider Architecture**:
  - **Primary Provider (`provider = "ollama"`)**: Local **Ollama** server running `qwen2.5:7b` at `http://localhost:11434` with 90-second execution timeout, 8192 context window (`num_ctx = 8192`), and 30-minute memory residency (`keep_alive = "30m"`). Handles shop data, products, business advice, and app help.
  - **General Knowledge Provider (`general_provider = "gemini"`)**: Google Gemini API (`gemini-3.5-flash`, timeout 20s). Handles non-shop general knowledge inquiries (science, history, sports, cooking, math, health basics, coding, jokes, current topics).
  - **Automated Failover**: If the selected provider fails, times out, or returns empty/garbled output, Dukaan Mitra retries once with the alternate provider. If both fail, it returns a friendly trouble message (`"I'm having a little trouble right now. Please try again in a moment."`) along with helpful suggestion chips.
  - **Cloud Privacy (`allow_cloud_for_shop_data = false`)**: Cloud provider (Gemini) never receives tool results, tool declarations, shop names, product names, sales numbers, or inventory counts. For general questions, Gemini receives only the user's current message and chat history with all shop data terms and numbers stripped.
- **Latency & Speedup Architecture**:
  - **Fast Path (Instant / ~1–30 ms)**: Greetings and name introductions ("hi I am <name>", "namaste", "vanakkam"), acknowledgements (`ok`, `thanks`, `got it`, `ठीक है`, `சரி`), negations (`no`, `nahi`, `illai`), and security refusals (passwords, tokens, prompt injection) are intercepted immediately by the rule engine across all languages without invoking model daemons.
  - **Direct Data Path (Instant / ~25–45 ms)**: Clear shop-wide inventory and sales inquiries (low stock, sales today/week/month, reorder recommendations, slow-moving items, top sellers, open purchase orders in English, Hindi, Tamil, Hinglish, Tanglish) run the appropriate backend tool directly and format the response using verified templates. Zero model latency and 100% database grounding.
  - **Selective Tool Provisioning**: When the model is invoked, only the tools strictly required for that query category are declared to the model (e.g. `[]` for general and advice questions, `get_product_stock` + `get_product_info` for product lookups). This minimizes prompt tokens and grammar evaluation overhead.
- **Identity & Confidentiality**: The assistant is strictly named "Dukaan Mitra" ("Your shop assistant") and is presented as StockSense's own AI. Responses never mention Ollama, Qwen, Google, Gemini, OpenAI, Anthropic, or model names.
- **Local Model Hardening**:
  - **Strict Tool Call Validation**: Every model tool invocation is whitelisted against allowed tools (`get_product_stock`, `list_low_stock`, `get_reorder_recommendations`, `get_sales_summary`, `top_selling_products`, `get_slow_moving_products`, `list_open_purchase_orders`, `get_product_info`, `get_shop_profile`, `get_shop_settings`, `get_product_price_and_margin`, `get_category_stock_totals`, `lowest_selling_products`, `get_dead_stock`, `get_purchase_order_status`, `get_import_history`, `get_out_of_stock_products`, `get_total_inventory_count`) and parameters validated against schemas. Unknown or malformed calls are discarded.
  - **Data Grounding Enforcement**: Numbers in answers must come strictly from verified tool results. If a model generates text for a shop data question without calling tools, the answer is discarded and regenerated from database-backed templates.
  - **Language & Script Validation**: For Hindi and Tamil queries, model outputs are verified against expected Devanagari/Tamil scripts and checked for garbled characters. Ungrounded or wrong-script outputs fall back to localized templates.
  - **Concise Length**: Outputs are bounded to 2 to 6 lines maximum.
- **Key Storage (Gemini Mode Only)**: When Gemini is enabled, the API key is loaded strictly on the server from `C:\xampp\StockSense_private\gemini.json` (outside `htdocs`). The key is never exposed to clients, git, Postman collections, logs, or error responses.
- **Multi-Tenant Scoping**: All tool executions and data lookups are strictly bound to the authenticated user's `shop_id` extracted from the Bearer token. No raw SQL or client-provided `shop_id` is permitted. Requests attempting to inspect another shop's data are strictly refused.
- **Brand & Safety Defense**: Output sanitization filters external model names (Ollama, Qwen, Gemini, OpenAI, Claude, etc.). Prompt injection attempts inside queries or product names to alter persona, reveal credentials, or modify system instructions are safely neutralized.
- **Fail-Safe Resilience**: If the AI model times out (90s limit for Ollama, 20s for Gemini), experiences connection failure, or the service is offline, Dukaan Mitra automatically falls back to intelligent keyword-driven rule execution. The API never returns HTTP 500 for AI issues.

#### Scope & System Rules
- **Shop Data Queries**:
  - Dukaan Mitra uses read-only tools to retrieve live shop metrics (inventory, low stock, sales, top sellers, dead stock, open POs, margins).
  - All numbers, quantities, dates, and amounts must originate exclusively from verified tool results.
  - If a shop question requires data that tools cannot provide, Dukaan Mitra states this plainly without hallucinating facts.
- **Wide Assistant Scope & General Knowledge**:
  - Answers ANY question helpfully, including general knowledge (science, history, sports, cooking recipes, math calculations, health basics, coding, jokes, current topics).
  - Topics are never refused simply because they are unrelated to running the shop.
- **Live / Recent Information Disclaimer**:
  - For live or recent information (live cricket scores, current weather, breaking news, stock tickers), states plainly that it cannot check live information and shares what it knows without pretending to have real-time access.
- **Professional Advice Disclaimer**:
  - For medical, legal, tax, or financial decisions, provides general context and gently recommends consulting a certified professional (such as a doctor, lawyer, or chartered accountant).
- **General Business & Retail Guidance**:
  - Answers questions on competing with nearby shops and supermarkets, attracting and retaining customers, customer loyalty programs, pricing strategies against competitors, profit margins, promotional offers, festival/seasonal stocking, reducing waste and dead stock (FIFO), supplier negotiations, store layout/display tips, cash flow basics, and how to use StockSense.
  - Merely discussing competitors, rivals, nearby supermarkets, or other stores in business strategy questions is fully supported and answered.
  - Always marks general guidance as advice using the prefix **`"General tip:"`**.
  - Provides 4 to 6 short lines of practical retail advice without inventing shop numbers, product names, quantities, or sales figures.
- **Strict Polite Refusal Policy**:
  - Refuses ONLY when the user asks to **ACCESS or SEE** another shop's private data (e.g. competitor's sales or stock records, other shops' prices or accounts, list of all shops), asks for passwords, tokens, API keys, or system instructions, or makes abusive/harmful requests.
  - Plain word matches on "competitor", "other shop", "rival", "token", "password", or "key" do NOT trigger a refusal without a clear access request.
  - Refusal Format: Exactly 1 short, respectful line in the user's language offering to help with their own shop's data or general business/pricing strategy, accompanied by suggestion chips (e.g., *"I can only access data for your own shop. I can help analyze your own stock and sales, or suggest competitive business strategies."*). Default `actions: []` (0 navigation buttons).
- **Greetings & Introductions**:
  - Treats "hi I am <name>", "hello, my name is <name>", "this is <name>", "namaste", "vanakkam" and Hindi/Tamil/Hinglish/Tanglish equivalents as greetings.
  - Responds immediately, greets the user by name if given (without storing it), states identity as Dukaan Mitra, and offers exactly 2 suggestions with 0 navigation actions.
- **Response Style & Formatting**:
  - **Length**: 2 to 6 short lines, concise and easy to read on mobile.
  - **Language**: Responds naturally in the language or dialect used by the shop owner (English, Tamil, Hindi, Tanglish, Hinglish).
  - **Numbers & Currency**: Formats currency with the rupee symbol and Indian numbering system (e.g., `₹1,500`, `₹1.5L`).
  - **Structured Written Text**:
    - *Low Stock*: Exact count of low/out of stock products, names with units left for top 3–5 items, followed by a next-step suggestion.
    - *Sales*: Total revenue (₹), bill count, total units sold, top selling product with units, followed by a next-step suggestion.
    - *Reorder*: Count of recommended products, names with recommended order quantities for top 3–5 items, followed by next-step advice.
    - *Slow Moving*: Count of slow-moving products, names with units in stock and cover days for top 3–5 items, followed by actionable advice.
  - **Actions vs. Suggestions**:
    - **`actions`**: Array of `[{"label": "...", "target": "...", "product_id"?: int}]`. Returned ONLY when a real answer provides a direct screen navigation shortcut (e.g., `"reorder"`, `"inventory"`, `"sales"`). Default greetings, refusals, and general advice return empty `actions: []`.
    - **`suggestions`**: Array of `[{"label": "...", "message": "..."}]`. Returned for greetings, refusals, advice, and conversation follow-ups. The mobile app renders these as clickable suggestion chips that post a normal chat message (e.g. `"How much did I sell today?"`) instead of navigating.

#### 11.1 Chat Query
- **Method & Path**: `POST /ai/chat.php`
- **Token Required**: Yes (`Authorization: Bearer <token>`)
- **Headers**: `Content-Type: application/json`
- **Rate Limits**: 100 questions per day per shop; 15 questions per minute per shop (`HTTP 429` on exceed).
- **Caching**: 5-minute cache for identical queries (`chat_{hash}` taking query, history context, and language into account).
- **Input Sanitization**: Truncated to 500 characters, control characters stripped.
- **Request Body**:
  ```json
  {
    "message": "Which items are low on stock?",
    "language_hint": "English",
    "history": [
      {
        "role": "user",
        "text": "What are my top selling products?"
      },
      {
        "role": "assistant",
        "text": "Your top sellers are Product Alpha A (20 units) and Product Beta B (15 units)."
      }
    ]
  }
  ```
  *(Notes:*
  - *`language_hint` is optional and only provided by the mobile app for messages originating from the voice sheet.*
  - *`history` is optional (max 6 items, roles `"user"` or `"assistant"`, text up to 300 chars, no cards). Passed to Gemini and rule-based fallback for conversation continuity.*
  - *Small-talk & Follow-ups: Acknowledgements (e.g., `"ok"`, `"thanks"`, `"theek hai"`, `"சரி"`) reply with a polite line in the conversation language and suggestion chips without restarting with a greeting. Affirmations (`"yes"`, `"haan"`, `"ஆம்"`) carry forward the previous assistant answer's suggestion, e.g. opening reorders).*
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Success",
    "data": {
      "answer": "You have 2 products running low on stock:\n• Product Alpha A (5 units left)\n• Product Beta B (3 units left)\n\nTap 'Open reorder list' below to create a purchase order, or ask me for reorder suggestions.",
      "cards": [
        {
          "product_id": 14,
          "name": "Product Alpha A",
          "sku": "SKU-A-01",
          "units_left": 5,
          "avg_daily_sales": 2.5,
          "status": "Low stock",
          "price": 50.0,
          "min_stock": 10
        }
      ],
      "actions": [
        {
          "label": "Open reorder list",
          "target": "reorder"
        },
        {
          "label": "View inventory",
          "target": "inventory"
        }
      ],
      "suggestions": [
        {
          "label": "What should I reorder?",
          "message": "What should I reorder this week?"
        },
        {
          "label": "Today's sales",
          "message": "How much did I sell today?"
        }
      ]
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Missing `message` or invalid JSON.
  - `401 Unauthorized`: Missing or invalid Bearer token.
  - `429 Too Many Requests`: Daily limit (100) or minute limit (15) exceeded.

#### 11.2 Explain Insight / Product Recommendation
- **Method & Path**: `POST /ai/explain.php`
- **Token Required**: Yes (`Authorization: Bearer <token>`)
- **Headers**: `Content-Type: application/json`
- **Caching**: 24-hour cache per product per day (`explain_{type}_{productId}_{date}`).
- **Request Body**:
  ```json
  {
    "type": "reorder",
    "product_id": 14
  }
  ```
  *(Supported `type` values: `"reorder"`, `"slow_moving"`, `"pair"`. Also accepts `"id"` as alias for `"product_id"`).*
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Success",
    "data": {
      "explanation": "Only 5 units of Product Alpha A remain. At your current sales rate of 2.5 units/day and typical 2-day supplier delivery, stock will be exhausted in 2.0 days. We recommend reordering now."
    }
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: Invalid type or missing `product_id`.
  - `401 Unauthorized`: Missing or invalid token.
  - `404 Not Found`: Product does not exist or belongs to another shop.

#### 11.3 Daily AI Summary
- **Method & Path**: `GET /ai/daily_summary.php`
- **Token Required**: Yes (`Authorization: Bearer <token>`)
- **Caching**: 24-hour cache per shop per day (`daily_summary_{shopId}_{date}`).
- **Behavior**: Generates 2 concise lines summarizing yesterday's sales performance and today's stock/reorder priorities.
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Success",
    "data": {
      "summary": "Yesterday's sales: ₹4,850 across 18 bills (Top seller: Amul Milk - 500 ml with 12 units).\nToday: 3 items are running low on stock. Check Reorder recommendations."
    }
  }
  ```
- **Error Responses**:
  - `401 Unauthorized`: Missing or invalid token.



