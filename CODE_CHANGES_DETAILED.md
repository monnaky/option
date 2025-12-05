# Detailed Code Changes
## Specific Code Modifications for Coolify Migration

This document provides detailed code examples of all changes made to support Coolify deployment.

---

## 1. config.php Changes

### Change 1: Environment Variable Support for Application Config

**Before:**
```php
define('APP_ENV', 'development');
define('APP_URL', 'http://localhost/vtm');
define('APP_TIMEZONE', 'UTC');
```

**After:**
```php
define('APP_ENV', $_ENV['APP_ENV'] ?? 'development');
define('APP_URL', $_ENV['APP_URL'] ?? 'http://localhost/vtm');
define('APP_TIMEZONE', $_ENV['APP_TIMEZONE'] ?? 'UTC');
```

**Impact:** Application now reads from environment variables, making it Docker/Coolify compatible while maintaining local development support.

---

### Change 2: Database Configuration with Environment Variables

**Before:**
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'vtm');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
```

**After:**
```php
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'vtm');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');
```

**Impact:** Database credentials can now be set via environment variables in Coolify, eliminating hardcoded values.

---

### Change 3: Encryption Key from Environment

**Before:**
```php
define('ENCRYPTION_KEY', '7f3a9b2c8d4e1f6a5b9c2d7e3f8a1b4c6d9e2f5a8b1c4d7e0f3a6b9c2d5e8f1a4');
```

**After:**
```php
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? '7f3a9b2c8d4e1f6a5b9c2d7e3f8a1b4c6d9e2f5a8b1c4d7e0f3a6b9c2d5e8f1a4');
```

**Impact:** Encryption key can be set securely via environment variables, improving security.

---

### Change 4: Deriv API Configuration

**Before:**
```php
define('DERIV_APP_ID', '105326');
define('DERIV_WS_HOST', 'ws.derivws.com');
```

**After:**
```php
define('DERIV_APP_ID', $_ENV['DERIV_APP_ID'] ?? '105326');
define('DERIV_WS_HOST', $_ENV['DERIV_WS_HOST'] ?? 'ws.derivws.com');
```

**Impact:** Deriv API settings can be customized per environment if needed.

---

### Change 5: Error Log Path Configuration

**Before:**
```php
ini_set('error_log', __DIR__ . '/error_log');
```

**After:**
```php
$logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/error_log';
ini_set('error_log', $logPath);
```

**Impact:** Error logs can be directed to container-friendly locations (e.g., `/var/log/php/error.log`).

---

## 2. app/config/Database.php Changes

### Change 1: Added Port Property

**Before:**
```php
private string $host;
private string $database;
private string $username;
private string $password;
private string $charset;
```

**After:**
```php
private string $host;
private string $port;  // NEW
private string $database;
private string $username;
private string $password;
private string $charset;
```

---

### Change 2: Port Initialization in Constructor

**Before:**
```php
private function __construct()
{
    $this->host = $_ENV['DB_HOST'] ?? 'localhost';
    $this->database = $_ENV['DB_NAME'] ?? 'vtmoption';
    $this->username = $_ENV['DB_USER'] ?? 'root';
    $this->password = $_ENV['DB_PASS'] ?? '';
    $this->charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
}
```

**After:**
```php
private function __construct()
{
    $this->host = $_ENV['DB_HOST'] ?? 'localhost';
    $this->port = $_ENV['DB_PORT'] ?? '3306';  // NEW
    $this->database = $_ENV['DB_NAME'] ?? 'vtmoption';
    $this->username = $_ENV['DB_USER'] ?? 'root';
    $this->password = $_ENV['DB_PASS'] ?? '';
    $this->charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
}
```

---

### Change 3: DSN Connection String with Port

**Before:**
```php
$dsn = sprintf(
    "mysql:host=%s;dbname=%s;charset=%s",
    $this->host,
    $this->database,
    $this->charset
);
```

**After:**
```php
$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=%s",
    $this->host,
    $this->port,  // NEW
    $this->database,
    $this->charset
);
```

**Impact:** Database connection now properly supports port configuration, which is essential for Docker environments where databases may use non-standard ports or require explicit port specification.

---

## 3. New Files Created

### Dockerfile

**Purpose:** Containerizes the PHP application for Coolify deployment.

**Key Features:**
- PHP 8.1 with Apache (for .htaccess compatibility)
- Required PHP extensions installed
- Apache mod_rewrite enabled
- Proper file permissions set
- Cron job configured
- OPcache enabled for performance
- Health check included

**Location:** Root directory (`Dockerfile`)

---

### docker-compose.yml

**Purpose:** Local testing environment that mimics Coolify setup.

**Key Features:**
- PHP application service
- MariaDB database service
- Environment variables configured
- Volume mounts for persistence
- Network configuration

**Location:** Root directory (`docker-compose.yml`)

**Usage:**
```bash
docker-compose up -d
```

---

## 4. Configuration Files Analysis

### .htaccess Files

**Status:** ✅ No changes required

**Reason:** Dockerfile uses Apache, so `.htaccess` files work as-is. If switching to Nginx later, conversion would be needed.

**Files:**
- `.htaccess` (root)
- `admin/.htaccess`
- `public/.htaccess`

---

## 5. Session Storage

### Current Implementation

**File:** `app/middleware/Authentication.php`

**Status:** ✅ Already compatible

**Reason:** Uses fallback paths that work in Docker:
```php
$fallbackPaths = [
    __DIR__ . '/../../storage/sessions',  // Works in Docker
    sys_get_temp_dir() . '/vtmoption_sessions',  // Fallback
    $defaultSessionPath  // System default
];
```

**Dockerfile Enhancement:**
```dockerfile
RUN mkdir -p /var/www/html/storage/sessions && \
    chown -R www-data:www-data /var/www/html/storage && \
    chmod -R 755 /var/www/html/storage
```

---

## 6. Path Usage Analysis

### Files Using Paths

**Status:** ✅ All compatible

**Analysis:**
- Uses `__DIR__` (relative paths) ✅
- Uses `BASE_PATH` (defined as `__DIR__`) ✅
- No hardcoded absolute paths found ✅
- No cPanel-specific paths found ✅

**Files Checked:**
- `config.php` - Uses `__DIR__`
- `app/bootstrap.php` - Uses relative paths
- `app/helpers.php` - Uses `$_SERVER` variables (works in Docker)
- All view files - Use relative paths

---

## 7. Cron Jobs

### Current Implementation

**File:** `cron/trading_loop.php`

**Status:** ✅ Compatible with minor path adjustment

**Dockerfile Configuration:**
```dockerfile
RUN echo "* * * * * www-data /usr/local/bin/php /var/www/html/cron/trading_loop.php >> /var/log/cron.log 2>&1" | crontab -u www-data
```

**Note:** Path is absolute (`/var/www/html/cron/trading_loop.php`) which works in Docker containers.

---

## 8. Error Handling

### Current Implementation

**Status:** ✅ Enhanced for Docker

**Changes:**
- Error log path now configurable via `LOG_PATH` environment variable
- Defaults to `__DIR__ . '/error_log'` for backward compatibility
- Can be set to `/var/log/php/error.log` in Docker

---

## 9. Backward Compatibility

### Maintained Features

✅ **Local Development:**
- All environment variables have fallback defaults
- Works without environment variables set
- Compatible with XAMPP/local development

✅ **Namecheap/cPanel:**
- Original functionality preserved
- Can still use hardcoded values (via fallbacks)
- No breaking changes for existing deployments

✅ **Coolify/Docker:**
- Full environment variable support
- Container-optimized configuration
- Dockerfile included

---

## 10. Testing the Changes

### Local Testing

1. **Test with Docker Compose:**
   ```bash
   docker-compose up -d
   docker-compose logs -f app
   ```

2. **Test Environment Variables:**
   - Verify fallbacks work (no env vars set)
   - Verify environment variables override defaults

3. **Test Database Connection:**
   - Verify connection with `DB_HOST=mariadb`
   - Verify port configuration works

### Production Testing

1. **Deploy to Coolify**
2. **Set environment variables**
3. **Verify database connection**
4. **Test all functionality**

---

## Summary of Changes

| File | Changes | Impact |
|------|---------|--------|
| `config.php` | Environment variable support | High - Enables Docker deployment |
| `app/config/Database.php` | Port support in DSN | High - Required for Docker databases |
| `Dockerfile` | New file | High - Containerizes application |
| `docker-compose.yml` | New file | Medium - Local testing |
| Documentation | Multiple new files | Medium - Migration guidance |

---

## Migration Impact

**Breaking Changes:** None

**New Requirements:**
- Environment variables must be set in Coolify
- Database service name must match `DB_HOST`
- Proper file permissions for storage directory

**Compatibility:**
- ✅ Backward compatible with existing deployments
- ✅ Works with local development
- ✅ Ready for Coolify deployment

---

## Next Steps

1. Review all changes
2. Test locally with Docker Compose
3. Deploy to Coolify following checklist
4. Verify all functionality
5. Monitor for issues

For detailed deployment instructions, see `COOLIFY_DEPLOYMENT_CHECKLIST.md`.

