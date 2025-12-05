# VTM Option - Namecheap Hosting Deployment Report

**Generated:** $(date)  
**Project Type:** PHP Web Application (Trading Bot Platform)  
**Database:** MySQL/MariaDB

---

## 📋 EXECUTIVE SUMMARY

This is a **PHP-based trading bot application** that integrates with the Deriv API for automated binary options trading. The application is designed for **shared hosting environments** like Namecheap and does NOT require Composer dependencies (uses pure PHP autoloader).

### Key Findings:
- ✅ **Compatible with Namecheap Shared Hosting**
- ✅ **No Composer dependencies required**
- ✅ **MySQL database required**
- ⚠️ **Cron jobs needed for automated trading**
- ⚠️ **WebSocket support required (uses PHP streams)**
- ⚠️ **Configuration needs updating for production**

---

## 1. PROJECT TYPE & ARCHITECTURE

### Project Type
- **Language:** PHP 7.4+ (Pure PHP, no framework)
- **Architecture:** MVC-like structure with custom autoloader
- **Database:** MySQL/MariaDB (PDO)
- **Web Server:** Apache (with mod_rewrite)
- **Session Storage:** File-based (in `storage/sessions/`)

### Project Structure
```
vtm/
├── app/                    # Application core
│   ├── config/            # Database configuration
│   ├── middleware/        # Security & authentication
│   ├── services/          # Business logic (DerivAPI, TradingBot)
│   └── utils/             # Helper utilities
├── api/                   # API endpoints
├── admin/                 # Admin dashboard
├── public/                # Public-facing files
├── cron/                  # Scheduled tasks
├── database/              # Migrations & setup
├── views/                 # HTML templates
├── storage/               # File storage (sessions, logs)
├── config.php             # Main configuration file
└── index.php              # Entry point
```

### Key Features
- Automated trading bot integration with Deriv API
- Real-time WebSocket connections
- User authentication & session management
- Admin dashboard
- Trading signal processing
- Encrypted API token storage

---

## 2. DATABASE ANALYSIS

### Database Type
- **Type:** MySQL/MariaDB
- **Engine:** InnoDB
- **Charset:** utf8mb4
- **Collation:** utf8mb4_unicode_ci

### Database Tables
The application requires the following tables:
1. `users` - User accounts
2. `settings` - User trading settings
3. `trading_sessions` - Active trading sessions
4. `trades` - Trade history
5. `signals` - Trading signals
6. `sessions` - JWT token sessions
7. `admin_users` - Admin accounts
8. `admin_activity_logs` - Admin activity tracking
9. `api_call_logs` - API call logging
10. `system_settings` - System configuration

### Database Connection Configuration
**Location:** `config.php` (lines 29-45)

**Current Settings:**
```php
DB_HOST: 'localhost'
DB_PORT: '3306'
DB_NAME: 'vtmoption'  // ⚠️ NEEDS UPDATE
DB_USER: 'root'      // ⚠️ NEEDS UPDATE
DB_PASS: ''          // ⚠️ NEEDS UPDATE
```

**⚠️ ACTION REQUIRED:**
- Update database credentials in `config.php` with Namecheap database details
- Database name format on Namecheap: `username_dbname`
- Database user format: `username_dbuser`
- Host is usually `localhost` (verify in cPanel)

### Database Migration
**Migration File:** `database/migrations/001_initial_schema.sql`

**Setup Script:** `database/setup.php`

**To Run Migration:**
```bash
php database/setup.php
```

Or import via phpMyAdmin in cPanel:
1. Log into cPanel → phpMyAdmin
2. Select your database
3. Click "Import"
4. Upload `database/migrations/001_initial_schema.sql`
5. Click "Go"

---

## 3. HOSTING REQUIREMENTS ASSESSMENT

### Server Requirements

#### PHP Requirements
- **Minimum PHP Version:** 7.4.0
- **Recommended PHP Version:** 8.0+ or 8.1+
- **PHP Extensions Required:**
  - ✅ `pdo` - Database abstraction
  - ✅ `pdo_mysql` - MySQL driver
  - ✅ `openssl` - Encryption/SSL
  - ✅ `mbstring` - String handling
  - ✅ `json` - JSON processing
  - ✅ `curl` - HTTP requests
  - ✅ `session` - Session management
  - ⚠️ `sockets` - Optional (for WebSocket, but uses streams as fallback)

#### PHP Configuration
- **Memory Limit:** 128MB minimum (256MB recommended)
- **Max Execution Time:** 300 seconds (for cron jobs)
- **Upload Max Filesize:** 10M
- **Post Max Size:** 10M

#### Web Server Requirements
- **Server:** Apache (Namecheap default)
- **Required Modules:**
  - `mod_rewrite` - URL rewriting
  - `mod_headers` - Security headers
  - `mod_deflate` - Compression (optional)
  - `mod_expires` - Browser caching (optional)
  - `mod_php7` or `mod_php8` - PHP processing

#### Namecheap Compatibility
✅ **Shared Hosting is SUFFICIENT** for this application

**Why Shared Hosting Works:**
- Pure PHP (no Node.js/Python)
- No Composer dependencies
- Standard MySQL database
- File-based sessions
- Uses PHP streams for WebSocket (no special server config)

**Potential Limitations:**
- Cron job execution frequency (usually 1-minute minimum)
- WebSocket connection stability (may need keep-alive)
- Memory limits (usually 256MB on shared hosting)
- Execution time limits (usually 300 seconds)

---

## 4. CONFIGURATION FILES REVIEW

### Main Configuration File: `config.php`

**⚠️ CRITICAL: This file needs updates for production!**

#### Environment Configuration (Lines 16-23)
```php
APP_ENV: 'development'  // ⚠️ CHANGE TO 'production'
APP_URL: 'http://localhost/vtmoption'  // ⚠️ UPDATE WITH YOUR DOMAIN
APP_TIMEZONE: 'UTC'  // ✅ OK
```

#### Database Configuration (Lines 29-45)
```php
DB_HOST: 'localhost'  // ✅ Usually correct for Namecheap
DB_PORT: '3306'  // ✅ Standard MySQL port
DB_NAME: 'vtmoption'  // ⚠️ UPDATE with Namecheap database name
DB_USER: 'root'  // ⚠️ UPDATE with Namecheap database user
DB_PASS: ''  // ⚠️ UPDATE with Namecheap database password
```

#### Security Configuration (Lines 51-55)
```php
ENCRYPTION_KEY: '7f3a9b2c8d4e1f6a5b9c2d7e3f8a1b4c6d9e2f5a8b1c4d7e0f3a6b9c2d5e8f1a4'
// ⚠️ GENERATE NEW KEY FOR PRODUCTION!
```

**To Generate New Encryption Key:**
```bash
php -r "echo bin2hex(random_bytes(32));"
```

#### Deriv API Configuration (Lines 64-69)
```php
DERIV_APP_ID: '105326'  // ✅ Default Deriv app ID
DERIV_WS_HOST: 'ws.derivws.com'  // ✅ Correct WebSocket host
```

### Apache Configuration: `.htaccess`

**Location:** Root directory and `public/` directory

**Key Features:**
- URL rewriting rules
- Security headers
- PHP configuration overrides
- File protection rules
- Gzip compression
- Browser caching

**⚠️ Note:** Some PHP settings in `.htaccess` may be overridden by Namecheap's PHP settings. Check cPanel → MultiPHP INI Editor.

### Autoloader: `app/autoload.php`

✅ **No Composer required** - Uses pure PHP autoloader

The application includes a custom autoloader that loads classes directly without Composer dependencies.

---

## 5. DEPLOYMENT PREPARATION

### Build Process
✅ **No build/compilation required**

This is a pure PHP application - just upload files and configure.

### Environment Setup Checklist

#### Pre-Deployment Tasks

1. **Generate New Encryption Key**
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
   Save this key - you'll need it for `config.php`

2. **Update `config.php`**
   - Set `APP_ENV` to `'production'`
   - Update `APP_URL` with your domain
   - Update database credentials
   - Update encryption key

3. **Prepare Database**
   - Create database in cPanel
   - Create database user in cPanel
   - Grant user permissions to database
   - Note down credentials

4. **File Permissions**
   - `storage/sessions/` - Must be writable (755 or 775)
   - `storage/` - Must be writable (755 or 775)
   - Root directory - Readable (755)
   - `config.php` - Readable (644) but protected by `.htaccess`

### File Structure for Hosting

**Optimal Structure:**
```
public_html/  (or your domain folder)
├── .htaccess
├── index.php
├── config.php
├── login.php
├── register.php
├── dashboard.php
├── trading.php
├── profile.php
├── logout.php
├── check_requirements.php
├── app/
├── api/
├── admin/
├── public/
├── cron/
├── database/
├── views/
└── storage/
    └── sessions/  (writable)
```

**⚠️ Important:** On Namecheap, your files typically go in:
- `public_html/` for main domain
- `public_html/subdomain/` for subdomains

### Static Assets
- CSS/JS: Served from `public/assets/`
- External CDN: Bootstrap, jQuery (already configured)
- No build process needed

---

## 6. SECURITY & PERFORMANCE CHECKS

### Security Issues Found

#### ⚠️ CRITICAL: Default Encryption Key
**Location:** `config.php` line 55

**Issue:** Using a default/hardcoded encryption key

**Fix:**
1. Generate new key: `php -r "echo bin2hex(random_bytes(32));"`
2. Update `ENCRYPTION_KEY` in `config.php`
3. **IMPORTANT:** If you have existing encrypted data, you'll need to re-encrypt with the new key

#### ⚠️ CRITICAL: Development Environment
**Location:** `config.php` line 17

**Issue:** `APP_ENV` is set to `'development'` (shows errors)

**Fix:** Change to `'production'` before deployment

#### ⚠️ WARNING: Error Log Exposure
**Location:** `error_log` file in root

**Issue:** Error logs may contain sensitive information

**Fix:**
- Ensure `.htaccess` blocks access to `.log` files (already configured)
- Regularly rotate/clean error logs
- Consider moving logs outside web root

#### ⚠️ WARNING: Debug Files
**Location:** `debug_websocket.log`

**Issue:** Debug logs may contain sensitive API information

**Fix:**
- Disable debug logging in production
- Ensure `.htaccess` blocks access (already configured)

#### ✅ GOOD: File Protection
- `.htaccess` properly blocks access to:
  - `config.php`
  - `.env` files
  - `.log` files
  - `.sql` files
  - Sensitive directories

#### ✅ GOOD: Security Headers
- X-Content-Type-Options
- X-XSS-Protection
- X-Frame-Options
- Content-Security-Policy
- Removed server information

### Performance Considerations

#### Large Files
- No large files detected
- Database schema is optimized with indexes

#### Caching Strategy
- Browser caching configured in `.htaccess`
- Session-based caching for user data
- No additional caching layer needed for shared hosting

#### CDN Requirements
- ✅ Already using CDN for Bootstrap/jQuery
- No additional CDN needed

---

## 7. SPECIFIC ITEMS TO IDENTIFY

### ✅ Missing Configuration Files
**Status:** All required files present

- ✅ `config.php` - Exists (needs updates)
- ✅ `.htaccess` - Exists (root and public)
- ✅ `app/autoload.php` - Exists
- ✅ Database migration - Exists

### ⚠️ Environment Variables That Need Setting

**All in `config.php` (not using .env file):**

1. `APP_ENV` - Set to `'production'`
2. `APP_URL` - Set to your domain (e.g., `'https://yourdomain.com'`)
3. `DB_HOST` - Usually `'localhost'` (verify in cPanel)
4. `DB_NAME` - Your Namecheap database name
5. `DB_USER` - Your Namecheap database user
6. `DB_PASS` - Your Namecheap database password
7. `ENCRYPTION_KEY` - Generate new 64-character hex string

### ⚠️ Database Setup Requirements

1. **Create Database in cPanel**
   - Go to cPanel → MySQL Databases
   - Create new database (e.g., `username_vtmoption`)
   - Note the full database name

2. **Create Database User**
   - Create new user in cPanel
   - Note the full username (e.g., `username_dbuser`)

3. **Grant Permissions**
   - Add user to database
   - Grant ALL PRIVILEGES

4. **Run Migration**
   - Via `database/setup.php` script, OR
   - Via phpMyAdmin import

### ✅ Dependencies That Need Installation

**Status:** No external dependencies required!

- ✅ Pure PHP application
- ✅ No Composer packages
- ✅ No npm packages
- ✅ No Python packages

### ✅ Build Steps Required

**Status:** No build process needed!

Just upload files and configure.

### ⚠️ Code Changes Needed for Production

1. **`config.php`** - Update all configuration values
2. **`.htaccess`** - Uncomment HTTPS redirect (line 10-11) if using SSL
3. **Error Reporting** - Already configured to hide errors in production

### ⚠️ File Permission Adjustments

**Required Permissions:**
```
storage/             755 (or 775)
storage/sessions/    755 (or 775) - MUST BE WRITABLE
config.php           644 (readable, protected by .htaccess)
All other files      644 (readable)
Directories          755 (readable/executable)
```

**To Set Permissions via cPanel:**
1. Go to File Manager
2. Right-click `storage/` folder
3. Change Permissions → 755
4. Repeat for `storage/sessions/`

**To Set Permissions via SSH (if available):**
```bash
chmod 755 storage
chmod 755 storage/sessions
chmod 644 config.php
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
```

### ⚠️ SSL/HTTPS Considerations

1. **Enable SSL in Namecheap**
   - Install free SSL via cPanel (Let's Encrypt)
   - Or use Namecheap SSL certificate

2. **Update `.htaccess`**
   - Uncomment lines 10-11 in `.htaccess`:
   ```apache
   RewriteCond %{HTTPS} off
   RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

3. **Update `config.php`**
   - Change `APP_URL` to use `https://`

4. **Update Session Cookie Security**
   - In `.htaccess` line 64, change:
   ```apache
   php_value session.cookie_secure 1  # Change from 0 to 1
   ```

---

## 8. HOSTING CHECKLIST FOR NAMECHEAP

### Pre-Deployment Tasks

- [ ] **1. Generate New Encryption Key**
  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```

- [ ] **2. Update `config.php`**
  - [ ] Set `APP_ENV` to `'production'`
  - [ ] Update `APP_URL` with your domain
  - [ ] Update `DB_NAME` with Namecheap database name
  - [ ] Update `DB_USER` with Namecheap database user
  - [ ] Update `DB_PASS` with Namecheap database password
  - [ ] Update `ENCRYPTION_KEY` with generated key

- [ ] **3. Prepare Database in cPanel**
  - [ ] Create database
  - [ ] Create database user
  - [ ] Grant user permissions
  - [ ] Note down all credentials

- [ ] **4. Enable SSL (Recommended)**
  - [ ] Install SSL certificate in cPanel
  - [ ] Test SSL is working

- [ ] **5. Check PHP Version**
  - [ ] Log into cPanel
  - [ ] Go to MultiPHP Manager
  - [ ] Select PHP 7.4+ or 8.0+
  - [ ] Verify required extensions are available

### Files to Upload

- [ ] **Upload All Files to `public_html/`**
  - [ ] All application files
  - [ ] `.htaccess` files
  - [ ] `config.php` (with updated values)

- [ ] **Verify File Structure**
  - [ ] All directories present
  - [ ] No missing files

### Database Setup Steps

- [ ] **1. Import Database Schema**
  - Option A: Via `database/setup.php` (if PHP CLI access)
  - Option B: Via phpMyAdmin
    1. Log into cPanel → phpMyAdmin
    2. Select your database
    3. Click "Import"
    4. Upload `database/migrations/001_initial_schema.sql`
    5. Click "Go"

- [ ] **2. Verify Tables Created**
  - [ ] Check all 10 tables exist
  - [ ] Verify indexes are created

- [ ] **3. Create Admin User (Optional)**
  - [ ] Run `database/setup.php` and create admin
  - [ ] Or manually insert via phpMyAdmin

### Configuration Changes

- [ ] **1. Set File Permissions**
  - [ ] `storage/` → 755
  - [ ] `storage/sessions/` → 755 (writable)

- [ ] **2. Update `.htaccess` for HTTPS**
  - [ ] Uncomment HTTPS redirect (if using SSL)

- [ ] **3. Update Session Cookie Security**
  - [ ] Set `session.cookie_secure` to `1` (if using HTTPS)

### Post-Deployment Verification

- [ ] **1. Test Requirements Check**
  - [ ] Visit: `https://yourdomain.com/check_requirements.php`
  - [ ] Verify all checks pass
  - [ ] Fix any issues

- [ ] **2. Test Database Connection**
  - [ ] Verify connection works
  - [ ] Check error logs if issues

- [ ] **3. Test Application**
  - [ ] Visit homepage
  - [ ] Test registration
  - [ ] Test login
  - [ ] Test dashboard
  - [ ] Test API endpoints

- [ ] **4. Set Up Cron Jobs**
  - [ ] Log into cPanel → Cron Jobs
  - [ ] Add cron job for `cron/trading_loop.php`:
    ```
    * * * * * /usr/bin/php /home/username/public_html/cron/trading_loop.php
    ```
  - [ ] Add cron job for `cron/signal_processor.php` (if needed)
  - [ ] Add cron job for `cron/contract_monitor.php` (if needed)

- [ ] **5. Test Cron Jobs**
  - [ ] Wait 1-2 minutes
  - [ ] Check error logs
  - [ ] Verify cron is executing

- [ ] **6. Security Check**
  - [ ] Test that `config.php` is not accessible via browser
  - [ ] Test that `.htaccess` blocks sensitive files
  - [ ] Verify SSL is working (if enabled)
  - [ ] Check security headers are present

- [ ] **7. Performance Check**
  - [ ] Test page load times
  - [ ] Check error logs for warnings
  - [ ] Verify WebSocket connections work

- [ ] **8. Clean Up**
  - [ ] Remove `check_requirements.php` (optional, for security)
  - [ ] Remove any test/debug files
  - [ ] Clear old error logs

---

## 9. STEP-BY-STEP DEPLOYMENT INSTRUCTIONS

### Step 1: Prepare Local Files

1. **Generate Encryption Key**
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
   Copy the output (64-character hex string)

2. **Update `config.php`**
   - Open `config.php` in a text editor
   - Change line 17: `define('APP_ENV', 'production');`
   - Change line 20: `define('APP_URL', 'https://yourdomain.com');`
   - Update database credentials (lines 36, 39, 42)
   - Update encryption key (line 55)

3. **Update `.htaccess` for HTTPS** (if using SSL)
   - Uncomment lines 10-11
   - Change line 64: `php_value session.cookie_secure 1`

### Step 2: Set Up Database in Namecheap cPanel

1. **Log into cPanel**
   - Go to your Namecheap account
   - Access cPanel

2. **Create Database**
   - Click "MySQL Databases"
   - Under "Create New Database", enter name: `vtmoption`
   - Click "Create Database"
   - Note the full database name (e.g., `username_vtmoption`)

3. **Create Database User**
   - Scroll to "Add New User"
   - Enter username and password
   - Click "Create User"
   - Note the full username (e.g., `username_dbuser`)

4. **Grant Permissions**
   - Scroll to "Add User to Database"
   - Select user and database
   - Click "Add"
   - Check "ALL PRIVILEGES"
   - Click "Make Changes"

### Step 3: Upload Files

1. **Access File Manager**
   - In cPanel, click "File Manager"
   - Navigate to `public_html/` (or your domain folder)

2. **Upload Files**
   - Click "Upload" button
   - Select all project files
   - Wait for upload to complete

   **OR use FTP:**
   - Use FTP client (FileZilla, etc.)
   - Connect to your Namecheap FTP
   - Upload all files to `public_html/`

3. **Verify Upload**
   - Check that all directories are present
   - Verify `config.php` is uploaded

### Step 4: Set File Permissions

1. **In File Manager**
   - Right-click `storage/` folder
   - Click "Change Permissions"
   - Set to `755`
   - Click "Change Permissions"

2. **Repeat for `storage/sessions/`**
   - Set to `755`

### Step 5: Import Database

**Option A: Via phpMyAdmin (Recommended)**
1. In cPanel, click "phpMyAdmin"
2. Select your database from left sidebar
3. Click "Import" tab
4. Click "Choose File"
5. Select `database/migrations/001_initial_schema.sql`
6. Click "Go"
7. Wait for import to complete
8. Verify tables are created

**Option B: Via Setup Script (if PHP CLI available)**
1. Access SSH (if available) or use cPanel Terminal
2. Navigate to project directory
3. Run: `php database/setup.php`
4. Follow prompts

### Step 6: Configure PHP

1. **Check PHP Version**
   - In cPanel, go to "MultiPHP Manager"
   - Select your domain
   - Choose PHP 7.4+ or 8.0+
   - Click "Apply"

2. **Verify PHP Extensions**
   - Go to "Select PHP Version"
   - Click "Extensions"
   - Verify required extensions are enabled:
     - pdo
     - pdo_mysql
     - openssl
     - mbstring
     - json
     - curl
     - session

### Step 7: Enable SSL (Recommended)

1. **Install SSL**
   - In cPanel, go to "SSL/TLS Status"
   - Select your domain
   - Click "Run AutoSSL" (for free Let's Encrypt)
   - Wait for installation

2. **Test SSL**
   - Visit `https://yourdomain.com`
   - Verify SSL is working

3. **Force HTTPS**
   - Update `.htaccess` (uncomment HTTPS redirect)
   - Update `config.php` (change APP_URL to https://)

### Step 8: Set Up Cron Jobs

1. **Access Cron Jobs**
   - In cPanel, click "Cron Jobs"

2. **Add Trading Loop Cron**
   - Select "Standard (cPanel v3.0 Style)"
   - Set time: `* * * * *` (every minute)
   - Command:
     ```
     /usr/bin/php /home/username/public_html/cron/trading_loop.php
     ```
   - Replace `username` with your cPanel username
   - Click "Add New Cron Job"

3. **Add Signal Processor Cron** (if needed)
   - Similar setup for `cron/signal_processor.php`

4. **Add Contract Monitor Cron** (if needed)
   - Similar setup for `cron/contract_monitor.php`

### Step 9: Test Deployment

1. **Test Requirements**
   - Visit: `https://yourdomain.com/check_requirements.php`
   - Verify all checks pass

2. **Test Homepage**
   - Visit: `https://yourdomain.com`
   - Should show landing page

3. **Test Registration**
   - Click "Register"
   - Create test account
   - Verify account is created

4. **Test Login**
   - Log in with test account
   - Verify dashboard loads

5. **Test API**
   - Try API endpoints
   - Check error logs if issues

6. **Test Cron Jobs**
   - Wait 1-2 minutes
   - Check error logs
   - Verify cron is executing

### Step 10: Final Security Checks

1. **Test File Protection**
   - Try accessing: `https://yourdomain.com/config.php`
   - Should be blocked (403 Forbidden)

2. **Test Error Log Protection**
   - Try accessing: `https://yourdomain.com/error_log`
   - Should be blocked

3. **Verify Security Headers**
   - Use browser DevTools → Network
   - Check response headers
   - Verify security headers are present

4. **Remove Test Files** (Optional)
   - Delete `check_requirements.php` if desired
   - Keep for troubleshooting

---

## 10. DATABASE MIGRATION/SETUP INSTRUCTIONS

### Method 1: phpMyAdmin (Recommended for Shared Hosting)

1. **Access phpMyAdmin**
   - Log into cPanel
   - Click "phpMyAdmin"

2. **Select Database**
   - Click your database name in left sidebar

3. **Import SQL File**
   - Click "Import" tab
   - Click "Choose File"
   - Select `database/migrations/001_initial_schema.sql`
   - Click "Go"

4. **Verify Import**
   - Check that all tables are created
   - Verify no errors

### Method 2: Command Line (If SSH Available)

1. **Access SSH**
   - Use cPanel Terminal or SSH client

2. **Navigate to Project**
   ```bash
   cd ~/public_html
   ```

3. **Run Setup Script**
   ```bash
   php database/setup.php
   ```

4. **Follow Prompts**
   - Enter database credentials if prompted
   - Verify tables are created

### Method 3: Manual SQL Execution

1. **Open SQL File**
   - Open `database/migrations/001_initial_schema.sql` in text editor

2. **Copy SQL Statements**
   - Copy all SQL statements

3. **Execute in phpMyAdmin**
   - Go to phpMyAdmin
   - Select database
   - Click "SQL" tab
   - Paste SQL
   - Click "Go"

### Post-Migration Verification

Run this SQL in phpMyAdmin to verify:
```sql
SHOW TABLES;
```

Should show 10 tables:
- users
- settings
- trading_sessions
- trades
- signals
- sessions
- admin_users
- admin_activity_logs
- api_call_logs
- system_settings

---

## 11. REMAINING TASKS BEFORE HOSTING

### Critical Tasks (Must Complete)

- [ ] **1. Update `config.php`**
  - [ ] Set `APP_ENV` to `'production'`
  - [ ] Update `APP_URL` with your domain
  - [ ] Update database credentials
  - [ ] Generate and set new encryption key

- [ ] **2. Create Database in Namecheap**
  - [ ] Create database
  - [ ] Create database user
  - [ ] Grant permissions

- [ ] **3. Enable SSL Certificate**
  - [ ] Install SSL in cPanel
  - [ ] Update `.htaccess` for HTTPS redirect
  - [ ] Update `config.php` APP_URL to https://

### Important Tasks (Should Complete)

- [ ] **4. Set File Permissions**
  - [ ] Set `storage/` to 755
  - [ ] Set `storage/sessions/` to 755

- [ ] **5. Import Database Schema**
  - [ ] Import via phpMyAdmin or setup script

- [ ] **6. Set Up Cron Jobs**
  - [ ] Configure trading loop cron
  - [ ] Configure signal processor cron (if needed)
  - [ ] Configure contract monitor cron (if needed)

- [ ] **7. Test Application**
  - [ ] Run requirements check
  - [ ] Test registration/login
  - [ ] Test API endpoints
  - [ ] Test cron jobs

### Optional Tasks (Nice to Have)

- [ ] **8. Performance Optimization**
  - [ ] Enable Gzip compression (already in .htaccess)
  - [ ] Enable browser caching (already in .htaccess)

- [ ] **9. Monitoring Setup**
  - [ ] Set up error log monitoring
  - [ ] Set up database backup schedule

- [ ] **10. Documentation**
  - [ ] Document admin credentials
  - [ ] Document API endpoints
  - [ ] Create user guide

---

## 12. TROUBLESHOOTING GUIDE

### Common Issues and Solutions

#### Issue 1: Database Connection Failed
**Symptoms:** Error message about database connection

**Solutions:**
1. Verify database credentials in `config.php`
2. Check database name format (usually `username_dbname`)
3. Verify database user has permissions
4. Check database host (usually `localhost`)
5. Verify database exists in cPanel

#### Issue 2: Permission Denied Errors
**Symptoms:** Cannot write to `storage/sessions/`

**Solutions:**
1. Set `storage/` folder to 755
2. Set `storage/sessions/` folder to 755
3. Verify folder ownership
4. Check if SELinux is blocking (unlikely on shared hosting)

#### Issue 3: 500 Internal Server Error
**Symptoms:** Blank page or 500 error

**Solutions:**
1. Check error logs in cPanel
2. Verify PHP version is 7.4+
3. Check `.htaccess` syntax
4. Verify required PHP extensions are enabled
5. Check file permissions

#### Issue 4: Cron Jobs Not Running
**Symptoms:** Trading bot not executing

**Solutions:**
1. Verify cron job syntax in cPanel
2. Check cron job path (use full path)
3. Verify PHP path (`/usr/bin/php` or check with `which php`)
4. Check error logs
5. Test cron job manually via SSH

#### Issue 5: WebSocket Connection Failed
**Symptoms:** Real-time features not working

**Solutions:**
1. Verify `openssl` extension is enabled
2. Check firewall settings (unlikely on shared hosting)
3. Verify `DERIV_WS_HOST` in `config.php`
4. Check error logs for WebSocket errors
5. Test API connection manually

#### Issue 6: SSL/HTTPS Issues
**Symptoms:** Mixed content warnings or redirect loops

**Solutions:**
1. Verify SSL certificate is installed
2. Check `.htaccess` HTTPS redirect syntax
3. Update `APP_URL` in `config.php` to use `https://`
4. Clear browser cache
5. Check SSL certificate expiration

#### Issue 7: Session Issues
**Symptoms:** Users getting logged out frequently

**Solutions:**
1. Verify `storage/sessions/` is writable
2. Check session configuration in `.htaccess`
3. Verify session lifetime settings
4. Check if session files are being cleaned up

---

## 13. POST-DEPLOYMENT MONITORING

### Daily Checks

- [ ] Check error logs for critical errors
- [ ] Verify cron jobs are executing
- [ ] Monitor database size
- [ ] Check application performance

### Weekly Checks

- [ ] Review user registrations
- [ ] Check trading activity
- [ ] Review API call logs
- [ ] Verify backups are running

### Monthly Checks

- [ ] Review security logs
- [ ] Update encryption keys (if needed)
- [ ] Review and clean old logs
- [ ] Check SSL certificate expiration

---

## 14. SUPPORT & RESOURCES

### Namecheap Resources
- **cPanel Documentation:** https://www.namecheap.com/support/knowledgebase/
- **Support:** https://www.namecheap.com/support/

### Application Resources
- **Requirements Check:** `https://yourdomain.com/check_requirements.php`
- **Error Logs:** Check in cPanel File Manager → `error_log`
- **WebSocket Debug Log:** `debug_websocket.log` (if enabled)

### PHP Resources
- **PHP Version Check:** `phpinfo()` page (create temporary file)
- **Extension Check:** `check_requirements.php`

---

## CONCLUSION

Your VTM Option application is **ready for deployment on Namecheap shared hosting** with the following key points:

✅ **Compatible:** Pure PHP application, no special requirements  
✅ **No Dependencies:** No Composer/npm packages needed  
✅ **Standard Setup:** Uses MySQL, Apache, standard PHP extensions  
⚠️ **Configuration Required:** Update `config.php` before deployment  
⚠️ **Cron Jobs Needed:** Set up automated trading tasks  
⚠️ **SSL Recommended:** Enable HTTPS for security  

**Estimated Deployment Time:** 1-2 hours (including testing)

**Next Steps:**
1. Follow the deployment checklist above
2. Update `config.php` with production values
3. Set up database and import schema
4. Upload files and configure permissions
5. Set up cron jobs
6. Test thoroughly
7. Monitor for issues

Good luck with your deployment! 🚀

