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
- `500 Internal Server Error`: Server failure (technical details logged to server `error_log`, generic error message returned to client).

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
    "sku": "string (required, unique per shop)",
    "name": "string (required)",
    "category": "string (optional)",
    "units": 100,
    "price": 25.00,
    "cost_price": 20.00,
    "min_stock_threshold": 15
  }
  ```
- **Success Response (`201 Created`)**

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
- **App Screen**: `ProductDetailScreen.kt`
- **Request Body**:
  ```json
  {
    "id": 1
  }
  ```
- **Success Response (`200 OK`)**:
  ```json
  {
    "status": true,
    "message": "Product deleted successfully."
  }
  ```
- **Error Response (`400 Bad Request`)**: If product has historical sales bills, deletion is rejected with a recommendation to archive.

---

### Module 4: Spreadsheet Bulk Import (`import/`)

Header detection is case-insensitive and ignores spaces, dashes, and underscores. The backend supports the following canonical fields and synonyms:
- `name` *(Required)*: `product name`, `name`, `item`, `item name`, `product`, `description`
- `sku` *(Required)*: `sku`, `code`, `item code`, `product code`, `barcode`
- `units` *(Required)*: `quantity`, `qty`, `units`, `stock`, `opening stock`
- `cost_price` *(Optional)*: `cost price`, `cost`, `purchase price`, `buying price`
- `price` *(Optional)*: `selling price`, `sale price`, `price`, `mrp`, `rate`
- `category` *(Optional)*: `category`, `type`, `group`
- `minimum_stock` *(Optional)*: `minimum stock`, `min stock`, `reorder level`, `threshold`

Validation Rule: If any of `name`, `sku`, or `units` (quantity) is not mapped, `validate.php` and `execute.php` reject the request with `400 Bad Request` ("Validation failed: ... column(s) are required and must be mapped.").

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
      }
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
- **Success Response (`200 OK`)**: Returns `mapped_columns_count`, `mappings`, `available_headers`, `total_rows`, and parsed `preview_rows`.

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
- **Validation Rule**: If `name`, `sku`, or `units` is unmapped or empty, returns `400 Bad Request`.
- **Success Response (`200 OK`)**: Returns `rows_checked`, `ready_rows`, `errors_remaining`, `ready_to_import`, row-level `errors` array, and `summary`.

#### 4.4 Execute Import
- **Method & Path**: `POST /import/execute.php`
- **Token Required**: Yes
- **App Screen**: `ImportInventoryReadyScreen.kt`, `InventoryImportedScreen.kt`
- **Request Body**:
  ```json
  {
    "file_token": "imp_2_abc123_1793164882.csv",
    "file_name": "stock_oct.xlsx",
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
  *(Note: If the store's `import_mode` is set to `REPLACE`, `confirm_replace: true` is strictly required).*
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
    "sale_date": "2026-10-29 10:15:00 (required, past/present only)",
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

