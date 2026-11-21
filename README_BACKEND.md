# StockSense Backend

Backend REST API services for the StockSense inventory intelligence platform, developed with PHP 8.1 and MySQL on the XAMPP stack.

---

## 1. System Requirements & Database Setup

### Requirements
- **Server Environment**: [XAMPP](https://www.apachefriends.org/) (Apache 2.4+ and MySQL 5.7+ / MariaDB 10.4+)
- **PHP Version**: PHP 8.1 or higher
- **Required PHP Extensions**:
  - `pdo_mysql` (Database access)
  - `curl` (Firebase HTTP v1 and AI API requests)
  - `openssl` (RS256 JWT generation for Firebase OAuth2)
  - `mbstring` (Multibyte string manipulation)
  - `json` (JSON payload encoding/decoding)

### Database Configuration
The central database configuration file is located at `config/db.php`:

```php
// config/db.php
$host = "localhost";
$dbname = "stocksense";
$username = "root";
$password = ""; // Set your local database password if configured
```

### Importing the Database
1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open **phpMyAdmin** in your browser at `http://localhost/phpmyadmin` (or use the MySQL CLI).
3. Create a new database named `stocksense` with collation `utf8mb4_unicode_ci`:
   ```sql
   CREATE DATABASE stocksense CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
4. Import the schema file `docs/schema.sql`:
   - Via phpMyAdmin: Select the `stocksense` database, navigate to the **Import** tab, choose `docs/schema.sql`, and click **Go**.
   - Via Command Line:
     ```bash
     mysql -u root -p stocksense < docs/schema.sql
     ```
5. Run the incremental migration scripts from the backend root folder:
   ```bash
   php migrations/01_create_import_mappings_table.php
   php migrations/02_add_idempotency_key_to_import_history.php
   php migrations/03_create_device_tokens_table.php
   php migrations/04_create_ai_tables.php
   php migrations/05_add_icon_columns_to_products.php
   ```

---

## 2. Private Storage Setup (Outside `htdocs`)

For security, all private API keys and service account credentials must be placed **outside** the web root (`htdocs`). This prevents private keys from being downloaded directly over HTTP requests.

Create a private directory on the host machine:
- **Windows**: `C:\xampp\StockSense_private\`
- **Linux / macOS**: `/var/www/StockSense_private/` (ensure web server read permissions)

### File 1: Firebase Service Account (`firebase-service-account.json`)
Used by `includes/FcmSender.php` to generate OAuth2 tokens and dispatch push notifications via the Firebase Cloud Messaging (FCM) HTTP v1 API.

1. In the [Firebase Console](https://console.firebase.google.com/), navigate to **Project Settings** > **Service accounts**.
2. Click **Generate new private key** and save the file.
3. Rename and place the downloaded file at:  
   `C:\xampp\StockSense_private\firebase-service-account.json`

**Expected Format (placeholders only; do not commit real values):**
```json
{
  "type": "service_account",
  "project_id": "your-firebase-project-id",
  "private_key_id": "your_private_key_id",
  "private_key": "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQD...\n-----END PRIVATE KEY-----\n",
  "client_email": "firebase-adminsdk-xxxxx@your-firebase-project-id.iam.gserviceaccount.com",
  "client_id": "123456789012345678901",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/..."
}
```

The file path is configured in `config/firebase.php`:
```php
return [
    'service_account_path' => 'C:\\xampp\\StockSense_private\\firebase-service-account.json',
    // ...
];
```

### File 2: AI Key Configuration (`gemini.json`)
Used by `includes/GeminiClient.php` to authenticate requests for the Dukaan Mitra retail assistant.

Create the file at:  
`C:\xampp\StockSense_private\gemini.json`

**Expected Format:**
```json
{
  "api_key": "YOUR_GEMINI_API_KEY"
}
```

The file path is configured in `config/ai.php`:
```php
return [
    'key_file_path' => 'C:\\xampp\\StockSense_private\\gemini.json',
    'model' => 'gemini-3.5-flash',
    'assistant_name' => 'Dukaan Mitra',
    // ...
];
```

---

## 3. Optional: Ollama Setup for Local Models

For deployments requiring fully offline operation, air-gapped security, or zero cloud dependencies, StockSense can integrate with a local LLM runner such as [Ollama](https://ollama.com/).

### 1. Install & Launch Ollama
1. Download and install Ollama from [ollama.com](https://ollama.com/).
2. Pull and test a lightweight local retail model (e.g., Llama 3.2 or Mistral):
   ```bash
   ollama pull llama3.2
   ollama run llama3.2
   ```
3. Ollama runs its local inference HTTP server at `http://localhost:11434`.

### 2. Configure `config/ai.php`
You can adjust `config/ai.php` to route requests to the local Ollama instance or use it as an offline fallback provider:

```php
return [
    // Primary cloud model configuration
    'key_file_path' => 'C:\\xampp\\StockSense_private\\gemini.json',
    'model' => 'gemini-3.5-flash',
    'assistant_name' => 'Dukaan Mitra',

    // Optional local model provider configuration
    'local_provider' => 'ollama',
    'ollama_base_url' => 'http://localhost:11434',
    'ollama_model' => 'llama3.2',

    // Rate limits and timeouts
    'timeout' => 15,
    'max_questions_per_day' => 100,
    'max_questions_per_minute' => 15,
    'cache_ttl_chat' => 300,
    'cache_ttl_explain' => 86400,
    'cache_ttl_daily_summary' => 86400,
    'max_tool_calls' => 3,
    'max_message_length' => 500,
];
```

> [!NOTE]
> Dukaan Mitra includes built-in keyword and rule-based deterministic fallback logic in `includes/AiEngine.php`. Even when external APIs are disconnected, the assistant continues answering shop inventory and sales queries accurately without returning errors.

---

## 4. Test Suites & Pre-Deployment Cleanup

### Running Backend Automated Tests
All test runners are designed for CLI execution and verify end-to-end functionality including database constraints, multi-tenant isolation, and rate limiting.

Run the test suites from the command line:

```bash
# Core authentication and multi-tenancy tests
php test_token_auth.php
php test_profile_and_edit.php

# Inventory and CSV import tests
php test_inventory_backend.php
php test_import_column_detection.php
php test_import_good_file.php
php test_import_existing_and_new_sku.php
php test_import_idempotency.php

# Sales and billing tests
php test_sales_backend.php
php test_sale_without_sale_date.php

# Reordering and store insights tests
php test_reorder_backend.php
php test_insights_backend.php

# Store settings tests
php test_settings_backend.php

# Push notifications and FCM tests
php test_push.php
php test_notifications_and_delete.php

# Dukaan Mitra AI assistant tests
php test_ai.php
```

---

### Files to Remove or Move Before Production Deployment

## 4. Tests, Diagnostics & Documentation Files

All test suites and diagnostic tools are isolated inside the `/tests/` directory with Apache HTTP access strictly forbidden (`Require all denied`). All Postman collections, SQL dumps, and sample spreadsheets are organized inside `/docs/`.

### Running All Automated Tests
To execute the complete test suite from PowerShell:
```powershell
powershell -ExecutionPolicy Bypass -File tests/run_all_tests.ps1
```

Individual test suites can be executed via CLI PHP:
```bash
php tests/test_token_auth.php
php tests/test_inventory_backend.php
php tests/test_ai.php
```

1. **Automated Test Runners (`tests/test_*.php`)**:
   - `tests/run_all_tests.ps1` (Comprehensive test runner)
   - `tests/test_ai.php`
   - `tests/test_token_auth.php`
   - `tests/test_inventory_backend.php`
   - `tests/test_sales_backend.php`
   - `tests/test_sale_without_sale_date.php`
   - `tests/test_reorder_backend.php`
   - `tests/test_insights_backend.php`
   - `tests/test_settings_backend.php`
   - `tests/test_push.php`
   - `tests/test_weekly_digest.php`
   - `tests/test_icon_classification.php`
   - `tests/test_notifications_and_delete.php`
   - `tests/test_profile_and_edit.php`
   - `tests/test_import_column_detection.php`
   - `tests/test_import_good_file.php`
   - `tests/test_import_existing_and_new_sku.php`
   - `tests/test_import_idempotency.php`
   - `tests/test_seed_demo_data.php`
   - `tests/ai_gap_test.php`
   - `tests/ai_compare.php`

2. **Diagnostic & Scratch Scripts (`tests/`)**:
   - `tests/ai_diagnose.php`
   - `tests/check_dai.php`
   - `tests/desc_products.php`
   - `tests/reproduce_bug.php`
   - `tests/seed_demo_data.php`
   - `tests/send_test_ai.php`
   - `tests/send_test_push.php`
   - `tests/temp_cleanup.php`

3. **Database Dumps & Sample Files (`docs/`)**:
   - `docs/schema.sql` (Full database table structures - tracked in repository)
   - `docs/05-11-2026.sql` (Database dump - gitignored)
   - `docs/06-11-2026.sql` (Database dump - gitignored)
   - `docs/ssdb.sql` (Database dump - gitignored)
   - `docs/sample_valid_import.csv`
   - `docs/sample_error_import.csv`
   - `docs/sample_stock_oct15.xlsx`
   - `docs/StockSense_Postman_Collection.json`
   - `docs/StockSense_Auth_Profile_Postman_Collection.json`

---

## 5. Demo Data Seeder (CLI Only)

A command-line seeding utility is provided at `cli/seed_demo_data.php` to populate realistic Indian supermarket demonstration data for presentations, testing, and UI validation.

### Execution Commands
```bash
# Seed demo data (safe to run; will notify if demo user already exists)
php cli/seed_demo_data.php

# Preview seeding calculations and summary without modifying the database
php cli/seed_demo_data.php --dry-run

# Purge existing demo shop data and regenerate fresh demo data
php cli/seed_demo_data.php --reset
```

### Seeded Data Characteristics
- **Demo Shop Owner**:
  - Email: `demo_owner@example.com`
  - Store: `Demo Supermarket` (`Indiranagar, Bengaluru`)
  - Password: A strong random 16-character password is generated at runtime and printed **once** in the CLI terminal. It is stored solely as a BCrypt hash and never recorded in plaintext, code, or logs.
- **30 Products Across 8 Categories**:
  - Categories: *Dairy, Grocery, Snacks, Beverages, Household, Personal Care, Bakery, Vegetables*.
  - Realistic Indian brand names, selling prices, purchase costs, server SKUs (`ColumnMatcher`), and catalog icon keys (`IconClassifier`).
- **Inventory Segments**:
  - **15 Fast-Moving**: Steady turnover, healthy stock levels above minimum threshold.
  - **5 Low-Stock**: Remaining units below `min_stock_threshold` (triggers reorder warnings).
  - **3 Slow-Moving**: Very low 30-day velocity, stock cover >= 60 days (triggers clearance recommendations).
  - **2 Dead-Stock**: Zero sales across the entire 30 days with stock on hand.
  - **1 Out-of-Stock**: 0 units remaining (triggers urgent reorder alert).
  - **4 Regular**: Stable everyday products with balanced stock.
- **30 Days of Sales History**:
  - Weekday vs. weekend footfall variations (higher basket sizes and bill frequencies on weekends).
  - Common paired purchases (Milk + Bread, Tea + Biscuits, Maggi + Chips, Veggie combos) to light up Frequently Bought Together insight rules.
  - Strict stock reduction: starting stock minus cumulative sales units equals current inventory units. Every bill's `total_amount` equals the exact sum of its line items.
- **2 Purchase Orders**:
  - `PO-2026-001` (Status: `placed`): Open reorder for out-of-stock Ghee and low-stock Toor Dal.
  - `PO-2026-002` (Status: `received`): Monthly restock received 8 days ago for Atta, Oil, and Salt.
- **Safety & Isolation**:
  - Restricted strictly to CLI (`php_sapi_name() === 'cli'`).
  - Uses prepared statements and transactions.
  - Touches and resets **only** the `demo_owner@example.com` shop owner; never reads or alters other users' records.

### Running the Seeder Test Suite
```bash
php tests/test_seed_demo_data.php
```

---

## 6. API Documentation

For the complete REST API specification covering all endpoints, request/response bodies, query parameters, error codes, and HTTP statuses, see:

👉 [**API_DOCUMENT.md**](API_DOCUMENT.md)

