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
4. Import the schema file `ssdb.sql`:
   - Via phpMyAdmin: Select the `stocksense` database, navigate to the **Import** tab, choose `ssdb.sql`, and click **Go**.
   - Via Command Line:
     ```bash
     mysql -u root -p stocksense < ssdb.sql
     ```
5. Run the incremental migration scripts from the backend root folder:
   ```bash
   php migrations/01_create_import_mappings_table.php
   php migrations/02_add_idempotency_key_to_import_history.php
   php migrations/03_create_device_tokens_table.php
   php migrations/04_create_ai_tables.php
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

> [!CAUTION]
> The following test scripts, diagnostic tools, and database dumps must be **removed** or **moved outside the web root** (`htdocs/`) before deploying to a production server. Leaving these files exposed to HTTP requests presents a critical security vulnerability.

1. **Automated Test Runners (`test_*.php`)**:
   - `test_ai.php`
   - `test_token_auth.php`
   - `test_inventory_backend.php`
   - `test_sales_backend.php`
   - `test_sale_without_sale_date.php`
   - `test_reorder_backend.php`
   - `test_insights_backend.php`
   - `test_settings_backend.php`
   - `test_push.php`
   - `test_notifications_and_delete.php`
   - `test_profile_and_edit.php`
   - `test_import_column_detection.php`
   - `test_import_good_file.php`
   - `test_import_existing_and_new_sku.php`
   - `test_import_idempotency.php`

2. **Diagnostic & Scratch Scripts**:
   - `ai_diagnose.php`
   - `check_dai.php`
   - `desc_products.php`
   - `reproduce_bug.php`
   - `seed_demo_data.php`
   - `send_test_ai.php`
   - `send_test_push.php`
   - `temp_cleanup.php`

3. **Database Dumps & Sample Files**:
   - `05-11-2026.sql`
   - `ssdb.sql`
   - `sample_valid_import.csv`
   - `sample_error_import.csv`
   - `sample_stock_oct15.xlsx`
   - `StockSense_Postman_Collection.json`
   - `StockSense_Auth_Profile_Postman_Collection.json`

---

## 5. API Documentation

For the complete REST API specification covering all endpoints, request/response bodies, query parameters, error codes, and HTTP statuses, see:

👉 [**API_DOCUMENT.md**](API_DOCUMENT.md)

