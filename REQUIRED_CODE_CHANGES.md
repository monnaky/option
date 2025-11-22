# Required Code Changes for Production Deployment

## File: config.php

### Change 1: Environment Setting (Line 17)
**Current:**
```php
define('APP_ENV', 'development');
```

**Change to:**
```php
define('APP_ENV', 'production');
```

**Reason:** Production environment hides errors and enables security features.

---

### Change 2: Application URL (Line 20)
**Current:**
```php
define('APP_URL', 'http://localhost/vtmoption');
```

**Change to:**
```php
define('APP_URL', 'https://yourdomain.com');
```

**Replace `yourdomain.com` with your actual domain name.**

**Reason:** Application needs correct base URL for redirects and links.

---

### Change 3: Database Name (Line 36)
**Current:**
```php
define('DB_NAME', 'vtmoption');
```

**Change to:**
```php
define('DB_NAME', 'namecheap_database_name');
```

**Replace with your Namecheap database name (format: `username_dbname`).**

**Reason:** Must match the database created in cPanel.

---

### Change 4: Database User (Line 39)
**Current:**
```php
define('DB_USER', 'root');
```

**Change to:**
```php
define('DB_USER', 'namecheap_database_user');
```

**Replace with your Namecheap database username (format: `username_dbuser`).**

**Reason:** Must match the database user created in cPanel.

---

### Change 5: Database Password (Line 42)
**Current:**
```php
define('DB_PASS', '');
```

**Change to:**
```php
define('DB_PASS', 'your_database_password');
```

**Replace with your Namecheap database password.**

**Reason:** Required for database connection.

---

### Change 6: Encryption Key (Line 55)
**Current:**
```php
define('ENCRYPTION_KEY', '7f3a9b2c8d4e1f6a5b9c2d7e3f8a1b4c6d9e2f5a8b1c4d7e0f3a6b9c2d5e8f1a4');
```

**Generate new key:**
```bash
php -r "echo bin2hex(random_bytes(32));"
```

**Change to:**
```php
define('ENCRYPTION_KEY', 'your_generated_64_character_hex_string');
```

**⚠️ IMPORTANT:** If you have existing encrypted data, you'll need to re-encrypt with the new key.

**Reason:** Security best practice - never use default/hardcoded encryption keys in production.

---

## File: .htaccess (Root)

### Change 7: Enable HTTPS Redirect (Lines 10-11)
**Current:**
```apache
# Force HTTPS (uncomment in production)
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Change to (uncomment):**
```apache
# Force HTTPS (uncomment in production)
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Only do this if you have SSL certificate installed.**

**Reason:** Forces all traffic to use HTTPS for security.

---

### Change 8: Secure Session Cookies (Line 64)
**Current:**
```apache
php_value session.cookie_secure 0
```

**Change to (if using HTTPS):**
```apache
php_value session.cookie_secure 1
```

**Only change if you have SSL certificate installed.**

**Reason:** Ensures session cookies are only sent over HTTPS.

---

## File: public/.htaccess

### Change 9: Enable HTTPS Redirect (Lines 10-11)
**Same as Change 7 above** - uncomment HTTPS redirect if using SSL.

### Change 10: Secure Session Cookies (Line 64)
**Same as Change 8 above** - set to 1 if using HTTPS.

---

## Summary of All Changes

1. ✅ `config.php` line 17: `APP_ENV = 'production'`
2. ✅ `config.php` line 20: `APP_URL = 'https://yourdomain.com'`
3. ✅ `config.php` line 36: `DB_NAME = 'namecheap_database_name'`
4. ✅ `config.php` line 39: `DB_USER = 'namecheap_database_user'`
5. ✅ `config.php` line 42: `DB_PASS = 'your_database_password'`
6. ✅ `config.php` line 55: `ENCRYPTION_KEY = 'new_generated_key'`
7. ⚠️ `.htaccess` lines 10-11: Uncomment HTTPS redirect (if SSL enabled)
8. ⚠️ `.htaccess` line 64: `session.cookie_secure = 1` (if SSL enabled)
9. ⚠️ `public/.htaccess` lines 10-11: Uncomment HTTPS redirect (if SSL enabled)
10. ⚠️ `public/.htaccess` line 64: `session.cookie_secure = 1` (if SSL enabled)

---

## Quick Copy-Paste Template

After making changes, your `config.php` should look like this (with your actual values):

```php
// Application environment (development or production)
define('APP_ENV', 'production');

// Application URL (your domain)
define('APP_URL', 'https://yourdomain.com');

// Database name (create this in cPanel)
define('DB_NAME', 'namecheap_database_name');

// Database username (from cPanel)
define('DB_USER', 'namecheap_database_user');

// Database password (from cPanel)
define('DB_PASS', 'your_database_password');

// Encryption key for API tokens (64-character hex string)
define('ENCRYPTION_KEY', 'your_generated_64_character_hex_string');
```

---

## Verification

After making changes, verify:

1. **Test Requirements:**
   - Visit: `https://yourdomain.com/check_requirements.php`
   - All checks should pass

2. **Test Database Connection:**
   - Try logging in or registering
   - Check error logs if connection fails

3. **Test HTTPS:**
   - Visit: `http://yourdomain.com`
   - Should redirect to `https://yourdomain.com`

4. **Test Security:**
   - Try accessing: `https://yourdomain.com/config.php`
   - Should be blocked (403 Forbidden)

