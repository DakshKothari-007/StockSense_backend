# StockSense Backend

> **Smarter Stock. Better Business.**

StockSense is an inventory intelligence and point-of-sale platform engineered for retail grocery stores and neighbourhood supermarkets.  
It unifies real-time stock tracking, barcode billing, automated spreadsheet ingestion, intelligent purchase reordering, and basket analytics.  
Powered by the bilingual Dukaan Mitra AI assistant, StockSense prevents stockouts, cuts dead inventory, and optimizes store working capital.

---

## Features

- **Authentication & Multi-Tenant Isolation**: Secure store owner signup and login, BCrypt password hashing, 64-character hexadecimal Bearer token session management, rate-limited login defense, and atomic cascade account deletion.
- **Home Dashboard & Valuation**: Real-time overview of total inventory valuation, active low-stock alerts, category stock distribution, daily revenue metrics, and quick operational shortcuts.
- **Inventory Management**: Searchable and filterable product catalog supporting instant queries, category filtering, multi-field sorting (name, price, stock units, recency), barcode/SKU tracking, and unit increment/decrement controls.
- **Product Details & CRUD**: Comprehensive stock inspection displaying unit counts, cost price, selling price, calculated gross margin, supplier details, restock thresholds, and product catalog icon assignments.
- **CSV & XLSX Bulk Import**: Intelligent spreadsheet ingestion featuring automatic column header detection, data mapping preview, dry-run validation with auto-fixing suggestions, zero-stock product creation, and atomic idempotent commit.
- **Point of Sale (POS) Billing**: Fast multi-item checkout, real-time stock availability verification, and immediate atomic inventory decrement upon bill completion.
- **Sales Analytics & History**: Transaction logs, period revenue tracking, top-selling product ranking, and hourly store foot-traffic trends across customizable timeframes.
- **Reorder & Purchase Orders**: Automated restock recommendations factoring in current inventory and incoming orders, automatic purchase order generation (`PO-YYYY-NNN`), order status management, one-tap stock intake, and safe delete/cancel enforcement.
- **Retail Insights & Market Basket Analysis**: Data-driven recommendations including slow-moving stock clearance alerts with margin protection, market basket paired-item association rules (lift > 1.0), and decision audit history.
- **Proactive Notifications & Weekly Digest**: Proactive alerts for low-stock thresholds and purchase order updates delivered via Firebase Cloud Messaging (FCM HTTP v1), an in-app notification center, and automated weekly email digests via PHPMailer.
- **Dukaan Mitra AI Assistant with Voice**: Built-in retail business co-pilot supporting bilingual conversational queries (English, Hindi, Tamil, Hinglish, Tanglish), voice input via `SpeechRecognizer`, speech synthesis playback via `TextToSpeech`, grounded shop metric tool calling (including stock valuation `stock_value`), retail business strategy advice, and resilient deterministic fallback.
- **Settings & Neobrutalist Design System**: High-contrast neobrutalist UI with light, dark (#070B20 background, #1A2560 cards), and system theme support, push notification toggles, low stock warning thresholds, and store profile customization.

---

## Tech Stack

### Backend REST Services
| Component | Technology | Version / Detail |
|---|---|---|
| **Server Environment** | Apache (XAMPP) | PHP 8.1+ |
| **Database** | MySQL / MariaDB | MySQL 5.7+ / MariaDB 10.4+, PDO with prepared statements |
| **Push Delivery** | Firebase Admin SDK | FCM HTTP v1 via OAuth2 JWT (Service Account) |
| **Email Delivery** | PHPMailer | SMTP Weekly Digest cron job |
| **AI Co-Pilot** | Ollama & Google Gemini | Hybrid local inference (`qwen2.5:7b` / `llama3.2`) + Cloud failover (`gemini-3.5-flash`) |

### Mobile Client (Android)
| Component | Technology | Version / Detail |
|---|---|---|
| **Language** | Kotlin | `2.2.10` |
| **UI Framework** | Jetpack Compose | Compose BOM `2026.02.01`, Material 3 |
| **Architecture** | MVVM | Lifecycle `2.6.1`, ViewModel Compose, Navigation Compose `2.8.0` |
| **Networking** | Retrofit & OkHttp | Retrofit `2.9.0`, Gson Converter `2.9.0`, OkHttp `4.12.0` |
| **Preferences** | Jetpack DataStore | Preferences DataStore `1.1.3` |
| **Push Notifications** | Firebase Cloud Messaging | Firebase BOM `33.9.0`, FCM `24.1.0` |

---

## How to Run

### 1. Backend Setup (XAMPP, MySQL, PHP, Ollama)

1. **Install Prerequisites**:
   - Install [XAMPP](https://www.apachefriends.org/) with PHP 8.1+ and MySQL.
   - Verify that required PHP extensions are active in `php.ini`:
     - `pdo_mysql`
     - `curl`
     - `openssl`
     - `mbstring`
     - `json`
2. **Place Backend in Web Root**:
   - Clone or copy the repository to:
     ```
     C:\xampp\htdocs\StockSense_backend
     ```
3. **Database Setup**:
   - Start **Apache** and **MySQL** from the XAMPP Control Panel.
   - Open phpMyAdmin (`http://localhost/phpmyadmin`) or the MySQL CLI.
   - Create the `stocksense` database:
     ```sql
     CREATE DATABASE stocksense CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   - Import the database schema from `docs/schema.sql`:
     ```bash
     mysql -u root -p stocksense < C:\xampp\htdocs\StockSense_backend\docs\schema.sql
     ```
   - Run incremental database migrations:
     ```bash
     cd C:\xampp\htdocs\StockSense_backend
     php migrations/01_create_import_mappings_table.php
     php migrations/02_add_idempotency_key_to_import_history.php
     php migrations/03_create_device_tokens_table.php
     php migrations/04_create_ai_tables.php
     php migrations/05_add_icon_columns_to_products.php
     php migrations/06_create_intent_misses_table.php
     ```
4. **Create Private Directory & Config Files**:
   - For security, credentials reside outside the web root (`htdocs`). Create:
     ```
     C:\xampp\StockSense_private\
     ```
   - Configure local credentials as described in the **Security & Configuration Note** section below.
5. **Set Up Ollama (Local AI Inference)**:
   - Download and install [Ollama](https://ollama.com/).
   - Pull the recommended retail model:
     ```bash
     ollama pull qwen2.5:7b
     ```
   - Ensure the Ollama daemon is running at `http://localhost:11434`.

---

### 2. Android App Setup (Android Studio)

1. **Prerequisites**:
   - Install **Android Studio Ladybug (2024.2+)** or newer with JDK 11+.
   - An Android device running Android 7.0 (API 24) or higher, or an Android Studio Virtual Device (AVD).
2. **Open and Sync**:
   - Open Android Studio, select **Open**, and choose the `StockSense` project directory.
   - Allow Gradle to sync and download required dependencies.
3. **Add Firebase Configuration**:
   - Place your `google-services.json` in the `app/` folder (see template below).
4. **Configure Backend URL**:
   - In `app/build.gradle.kts`, set `BASE_URL` in `buildTypes.debug`:
     - **Android Emulator**: `"http://10.0.2.2/StockSense_backend/"`
     - **Physical Device**: `"http://<YOUR_LOCAL_IP>/StockSense_backend/"` (e.g. `http://192.168.1.100/StockSense_backend/`, phone and PC on same Wi-Fi)
     - **Tunneling**: `"https://<YOUR_TUNNEL>.ngrok-free.app/StockSense_backend/"`
5. **Build and Run**:
   - Select your target device or emulator in Android Studio and press **Run 'app'** (`Shift + F10`).

---

## How to Run the Tests

### Backend Automated Test Suites
The backend contains 23 comprehensive test suites validating authentication, rate limiting, multi-tenant isolation, inventory imports, sales calculations, reorder logic, purchase order delete rules, and AI intents.

To run the complete test suite from PowerShell:
```powershell
powershell -ExecutionPolicy Bypass -File tests/run_all_tests.ps1
```

To run individual test suites via PHP CLI:
```bash
php tests/test_token_auth.php
php tests/test_inventory_backend.php
php tests/test_reorder_backend.php
php tests/test_purchase_order_delete_rule.php
php tests/test_ai.php
```

### Android Unit Tests
The Android project includes unit tests for ViewModels, repository layers, and UI card formatting (including purchase order cards and import column detection).

To run unit tests via Gradle CLI:
```bash
# From Android project root
.\gradlew testDebugUnitTest

# Re-run all tests cleanly
.\gradlew testDebugUnitTest --rerun-tasks
```

---

## Folder Structure

```
StockSense_backend/
├── ai/                                    # Dukaan Mitra AI assistant endpoints
│   ├── chat.php                           # Bilingual conversational query endpoint
│   ├── daily_summary.php                  # Morning sales & stock summary
│   └── explain.php                        # AI-driven recommendation explainability
├── auth/                                  # Authentication & store profile endpoints
│   ├── signup.php                         # Owner registration
│   ├── login.php                          # Rate-limited login with Bearer token issuance
│   ├── logout.php                         # Session revocation
│   ├── profile.php                        # Owner & store profile details
│   ├── update_profile.php                 # Update name and email
│   ├── update_shop.php                    # Update supermarket brand & location
│   ├── forgot_password.php                # Password reset initiation
│   ├── reset_password.php                 # Password update & multi-device session logout
│   └── delete_account.php                 # Complete atomic cascade account deletion
├── cli/                                   # Command-line administrative utilities
│   ├── backfill_product_icons.php         # Catalog icon backfill script
│   ├── review_intent_misses.php           # Audit low-confidence AI query misses
│   └── seed_demo_data.php                 # CLI demo supermarket data seeder
├── config/                                # Internal application configuration
│   ├── ai.php                             # Dual LLM provider & caching settings
│   ├── db.php                             # PDO MySQL database connection
│   ├── firebase.php                       # FCM HTTP v1 credential path mapping
│   └── rate_limiter.php                   # Account & IP rate limiting configuration
├── cron/                                  # Scheduled background tasks
│   ├── backfill_product_icons.php         # Periodic icon classifier worker
│   ├── daily_summary.php                  # Daily summary notification cron
│   └── weekly_digest.php                  # Automated weekly email digest (PHPMailer)
├── dashboard/                             # Supermarket overview metrics
│   └── summary.php                        # Total valuation, low stock & sales metrics
├── docs/                                  # Database schema dumps and sample files
│   ├── schema.sql                         # Complete database table structure
│   ├── sample_valid_import.csv            # Sample valid product CSV
│   ├── sample_error_import.csv            # Sample CSV with validation errors
│   └── sample_stock_oct15.xlsx            # Sample Excel spreadsheet
├── import/                                # Bulk spreadsheet ingestion engine
│   ├── ColumnMatcher.php                  # Header detection & semantic column mapping
│   ├── SpreadsheetReader.php              # Native CSV and XLSX parser
│   ├── upload.php                         # Multipart file upload handler (<= 5MB)
│   ├── preview.php                        # Column mapping preview & header inspection
│   ├── validate.php                       # Row-level validation & auto-fix diagnostics
│   ├── execute.php                        # Atomic transactional import execution
│   └── get_history.php                    # Historical import audit logs
├── includes/                              # Core backend services & engines
│   ├── AdvisorEngine.php                  # 12-topic retail business advisory engine
│   ├── AiClientFactory.php                # Hybrid LLM provider factory
│   ├── AiEngine.php                       # Core AI co-pilot execution & tool execution
│   ├── AiModelClient.php                  # Abstract LLM client interface
│   ├── AiTools.php                        # Grounded database inspection tools
│   ├── FcmSender.php                      # FCM HTTP v1 OAuth2 push dispatcher
│   ├── GeminiClient.php                   # Google Gemini API client
│   ├── IconClassifier.php                 # Automated catalog icon classifier
│   ├── IntentEngine.php                   # 5-layer bilingual intent understanding engine
│   ├── MailClient.php                     # PHPMailer SMTP client
│   ├── OllamaClient.php                   # Local Ollama client
│   ├── PushNotifier.php                   # Proactive push notification rules
│   └── WeeklyDigestService.php            # Weekly digest calculation & HTML templating
├── inventory/                             # Product catalog & inventory CRUD
│   ├── add_product.php                    # Add single product
│   ├── auth_helper.php                    # Shared Bearer token authentication
│   ├── delete_product.php                 # Delete product
│   ├── get_product_detail.php             # Detailed product view
│   ├── get_products.php                   # Paginated product list with search/filter
│   ├── import_inventory.php               # Batch JSON product import
│   └── update_product.php                 # Update product attributes and stock units
├── migrations/                            # Incremental schema migration scripts
│   ├── 01_create_import_mappings_table.php
│   ├── 02_add_idempotency_key_to_import_history.php
│   ├── 03_create_device_tokens_table.php
│   ├── 04_create_ai_tables.php
│   ├── 05_add_icon_columns_to_products.php
│   └── 06_create_intent_misses_table.php
├── notifications/                         # In-app notifications & push registrations
│   ├── dismiss.php                        # Dismiss notification
│   ├── list.php                           # List notifications
│   ├── mark_all_read.php                  # Mark all notifications read
│   ├── mark_read.php                      # Mark single notification read
│   ├── register_device.php                # Register FCM device token
│   ├── unread_count.php                   # Unread notification counter
│   └── unregister_device.php              # Remove FCM device token
├── reorder/                               # Reorder recommendations & purchase orders
│   ├── cancel.php                         # Cancel purchase order
│   ├── create_order.php                   # Place purchase order (PO-YYYY-NNN)
│   ├── delete.php                         # Safe delete for open, unreceived orders
│   ├── list.php                           # Restock recommendations
│   ├── orders.php                         # Purchase orders list with line items & icons
│   └── receive.php                        # Mark received and atomically increment stock
├── sales/                                 # Point of sale & billing
│   ├── create.php                         # Record sale bill with atomic stock decrement
│   ├── import.php                         # Batch sales history import
│   ├── list.php                           # Paginated transaction list
│   ├── product_history.php                # 7-day product sales velocity
│   └── summary.php                        # Revenue metrics, top sellers & hourly trends
├── settings/                              # Supermarket preferences & configuration
│   ├── change_password.php                # Rate-limited password change
│   ├── get.php                            # Get store settings
│   ├── reset_defaults.php                 # Reset settings to default values
│   ├── settings_helper.php                # Centralized settings retrieval
│   └── update.php                         # Update settings (theme, alerts, thresholds)
├── tests/                                 # 23 automated backend test suites
│   └── run_all_tests.ps1                  # Comprehensive PowerShell test runner
├── API_DOCUMENT.md                        # Complete REST API specification
└── README.md
```

---

## Security & Configuration Note

> [!IMPORTANT]
> **API keys, service account credentials, and database passwords are never committed to version control.**  
> Create the following configuration files on your local machine using the placeholder templates below:

### 1. `config/db.php` (Backend Web Root)
Create at `C:\xampp\htdocs\StockSense_backend\config\db.php`:
```php
<?php
$host = "localhost";
$dbname = "stocksense";
$username = "root";
$password = ""; // Your local database password if configured
```

### 2. `firebase-service-account.json` (Private Storage)
Create at `C:\xampp\StockSense_private\firebase-service-account.json`:
```json
{
  "type": "service_account",
  "project_id": "your-firebase-project-id",
  "private_key_id": "YOUR_PRIVATE_KEY_ID",
  "private_key": "-----BEGIN PRIVATE KEY-----\nYOUR_PRIVATE_KEY_HERE\n-----END PRIVATE KEY-----\n",
  "client_email": "firebase-adminsdk-xxxxx@your-firebase-project-id.iam.gserviceaccount.com",
  "client_id": "000000000000000000000",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/..."
}
```

### 3. `gemini.json` (Private Storage - Optional Cloud AI)
Create at `C:\xampp\StockSense_private\gemini.json`:
```json
{
  "api_key": "YOUR_GEMINI_API_KEY"
}
```

### 4. `app/google-services.json` (Android Repo)
Download this file from your [Firebase Console](https://console.firebase.google.com/) (`Project Settings > General > Your Apps`) and place it in `StockSense/app/`.

*All secrets, passwords, and tokens shown in this document and throughout the repository are placeholders only.*

---

## API Specification

For the complete REST API documentation covering all endpoints, query parameters, request/response bodies, status codes, and error formats, refer to:

👉 [**API_DOCUMENT.md**](API_DOCUMENT.md)
