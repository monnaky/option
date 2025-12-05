# Coolify VPS Migration Report
## Comprehensive Code Review & Migration Guide

**Project:** VTM Option - PHP Trading Bot Application  
**Source:** Namecheap/cPanel Shared Hosting  
**Target:** Coolify VPS (Docker-based)

---

## Executive Summary

This report provides a comprehensive analysis of the PHP project's compatibility with Coolify's Docker-based VPS environment. The application is well-structured but requires several modifications to transition from traditional shared hosting to containerized deployment.

### Key Findings

✅ **Strengths:**
- Clean separation of concerns
- Environment variable support already partially implemented
- Relative path usage (mostly compatible)
- No hardcoded cPanel-specific paths found

⚠️ **Critical Issues:**
- Database connection uses `localhost` (needs container hostname)
- Hardcoded database credentials in `config.php`
- `.htaccess` Apache directives need Nginx conversion
- Session storage paths need Docker volume consideration
- Error logging paths need container-friendly approach

---

## 1. DATABASE CONFIGURATION ANALYSIS

### Current State

**File:** `config.php` (Lines 29-45)
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'vtm');
define('DB_USER', 'root');
define('DB_PASS', '');
```

**File:** `app/config/Database.php` (Lines 33-37)
```php
$this->host = $_ENV['DB_HOST'] ?? 'localhost';
$this->database = $_ENV['DB_NAME'] ?? 'vtmoption';
$this->username = $_ENV['DB_USER'] ?? 'root';
$this->password = $_ENV['DB_PASS'] ?? '';
```

### Issues Identified

1. **❌ Hardcoded `localhost`**: Will not work in Docker where database is a separate container
2. **❌ Missing Port Support**: Database.php doesn't use DB_PORT from environment
3. **❌ Default Credentials**: Root user with empty password is insecure
4. **❌ No Connection Pooling**: Could benefit from persistent connections in containerized environment

### Required Changes

#### 1.1 Update `config.php` to Use Environment Variables

**Current:**
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'vtm');
define('DB_USER', 'root');
define('DB_PASS', '');
```

**Required:**
```php
// Use environment variables with fallbacks for local development
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'vtm');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
```

#### 1.2 Update `app/config/Database.php` to Support Port

**Current:**
```php
$dsn = sprintf(
    "mysql:host=%s;dbname=%s;charset=%s",
    $this->host,
    $this->database,
    $this->charset
);
```

**Required:**
```php
$port = $_ENV['DB_PORT'] ?? '3306';
$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=%s",
    $this->host,
    $port,
    $this->database,
    $this->charset
);
```

### Coolify Environment Variables

Set these in Coolify's environment configuration:

```bash
DB_HOST=mariadb  # or mysql, depending on your service name
DB_PORT=3306
DB_NAME=vtmoption
DB_USER=vtmoption_user
DB_PASS=your_secure_password_here
```

**Note:** In Coolify, the database service name becomes the hostname. If you create a MariaDB service named `mariadb`, use `DB_HOST=mariadb`.

---

## 2. FILE PATH & DIRECTORY STRUCTURE REVIEW

### Current Path Usage

**Analysis Results:**

✅ **Good Practices Found:**
- Uses `__DIR__` for relative paths (Docker-compatible)
- `BASE_PATH` defined as `__DIR__` (works in containers)
- Session storage uses relative paths with fallbacks

⚠️ **Potential Issues:**

1. **Error Log Path** (`config.php` line 88):
   ```php
   ini_set('error_log', __DIR__ . '/error_log');
   ```
   - ✅ Works in Docker, but consider using `/var/log/php/error.log` for better log management

2. **Session Storage** (`app/middleware/Authentication.php` line 38):
   ```php
   __DIR__ . '/../../storage/sessions'
   ```
   - ⚠️ Needs writable volume in Docker
   - ✅ Already has fallback to system temp directory

3. **Helper Functions** (`app/helpers.php`):
   - Uses `$_SERVER['DOCUMENT_ROOT']` and `$_SERVER['SCRIPT_FILENAME']`
   - ✅ These work in Docker, but behavior may differ with Nginx

### Required Changes

#### 2.1 Session Storage Volume

Ensure `storage/sessions/` is writable. In Dockerfile:
```dockerfile
RUN mkdir -p /var/www/html/storage/sessions && \
    chown -R www-data:www-data /var/www/html/storage
```

#### 2.2 Error Logging

Update `config.php` to use container-friendly log path:
```php
// Use environment variable for log path
$logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/error_log';
ini_set('error_log', $logPath);
```

---

## 3. ENVIRONMENT CONFIGURATION

### Hardcoded Values Found

| Location | Current Value | Should Be |
|----------|--------------|-----------|
| `config.php:17` | `APP_ENV = 'development'` | Environment variable |
| `config.php:20` | `APP_URL = 'http://localhost/vtm'` | Environment variable |
| `config.php:30` | `DB_HOST = 'localhost'` | Environment variable |
| `config.php:36` | `DB_NAME = 'vtm'` | Environment variable |
| `config.php:39` | `DB_USER = 'root'` | Environment variable |
| `config.php:42` | `DB_PASS = ''` | Environment variable |
| `config.php:55` | `ENCRYPTION_KEY` (hardcoded) | Environment variable |
| `config.php:65` | `DERIV_APP_ID = '105326'` | Environment variable (optional) |

### Required Environment Variables for Coolify

Create these in Coolify's environment settings:

```bash
# Application
APP_ENV=production
APP_URL=https://yourdomain.com
APP_TIMEZONE=UTC

# Database
DB_HOST=mariadb
DB_PORT=3306
DB_NAME=vtmoption
DB_USER=vtmoption_user
DB_PASS=your_secure_password

# Security
ENCRYPTION_KEY=your_64_character_hex_key_here

# Deriv API
DERIV_APP_ID=105326
DERIV_WS_HOST=ws.derivws.com

# Optional
LOG_PATH=/var/log/php/error.log
```

---

## 4. SESSION & FILE STORAGE

### Current Implementation

**File:** `app/middleware/Authentication.php`

Session storage uses fallback paths:
```php
$fallbackPaths = [
    __DIR__ . '/../../storage/sessions',
    sys_get_temp_dir() . '/vtmoption_sessions',
    $defaultSessionPath
];
```

### Docker Considerations

1. **Session Persistence**: Sessions stored in container filesystem are lost on container restart
   - **Solution**: Use Redis or ensure volume persistence
   - **Alternative**: Use database sessions (requires schema change)

2. **File Permissions**: Container user (www-data) must have write access
   - **Solution**: Set proper ownership in Dockerfile

3. **Volume Mounting**: Consider mounting `storage/` as a volume for persistence

### Recommended Approach

**Option 1: File-based (Simpler)**
- Mount `storage/` as Docker volume
- Ensure proper permissions

**Option 2: Redis (Better for scaling)**
- Use Redis for session storage
- Requires PHP Redis extension
- More complex but better for production

**Option 3: Database Sessions**
- Store sessions in database
- Requires schema modification
- Best for multi-container deployments

---

## 5. DEPENDENCY & EXTENSION CHECK

### Required PHP Extensions

Based on `check_requirements.php`:

**Required:**
- ✅ `pdo` - Database access
- ✅ `pdo_mysql` - MySQL/MariaDB support
- ✅ `openssl` - Encryption
- ✅ `mbstring` - String handling
- ✅ `json` - JSON processing
- ✅ `curl` - HTTP requests
- ✅ `session` - Session management

**Optional:**
- `sockets` - WebSocket support (used by DerivAPI)
- `zip` - File compression
- `gd` - Image processing

### Docker PHP Image Recommendation

Use official PHP image with Apache or PHP-FPM:

```dockerfile
FROM php:8.1-apache
# or
FROM php:8.1-fpm
```

**Required Extensions Installation:**
```dockerfile
RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN docker-php-ext-enable pdo_mysql
```

**Note:** `openssl`, `mbstring`, `json`, `curl`, and `session` are typically included in PHP base images.

---

## 6. .HTACCESS TO NGINX CONVERSION

### Current .htaccess Rules

The project uses Apache `.htaccess` files. Coolify typically uses Nginx.

### Key Rules to Convert

1. **URL Rewriting** (`.htaccess` lines 5-42)
2. **Security Headers** (`.htaccess` lines 46-62)
3. **PHP Configuration** (`.htaccess` lines 65-86)
4. **File Protection** (`.htaccess` lines 92-101)

### Solutions

**Option 1: Use Apache in Docker**
- Use `php:8.1-apache` image
- `.htaccess` files work as-is
- ✅ Easiest migration path

**Option 2: Use Nginx + PHP-FPM**
- Convert `.htaccess` to Nginx config
- More complex but better performance
- Requires Nginx configuration in Coolify

**Recommendation:** Start with Apache for easier migration, consider Nginx later for optimization.

---

## 7. CRON JOBS & SCHEDULED TASKS

### Current Cron Jobs

**File:** `cron/trading_loop.php`
```php
// Should run every minute
* * * * * /usr/bin/php /path/to/cron/trading_loop.php
```

### Coolify Approach

**Option 1: Container Cron**
- Add cron to Dockerfile
- Requires `cron` package installation
- Sessions persist within container

**Option 2: External Cron Service**
- Use Coolify's scheduled tasks (if available)
- Or separate cron container
- Better for container orchestration

**Option 3: Application-level Scheduling**
- Implement queue system
- Use background workers
- Most scalable approach

### Recommended Implementation

For initial migration, use container cron:

```dockerfile
# Install cron
RUN apt-get update && apt-get install -y cron

# Add cron job
RUN echo "* * * * * www-data /usr/local/bin/php /var/www/html/cron/trading_loop.php >> /var/log/cron.log 2>&1" | crontab -u www-data

# Start cron service
CMD ["sh", "-c", "cron && apache2-foreground"]
```

---

## 8. MIGRATION STEPS

### Pre-Migration Checklist

- [ ] Backup current database from Namecheap
- [ ] Export all data (users, trades, settings)
- [ ] Document current configuration values
- [ ] Test database connection locally with new credentials
- [ ] Generate new encryption key for production

### Step 1: Database Migration

1. **Export Database from Namecheap**
   ```bash
   mysqldump -u username -p database_name > backup.sql
   ```

2. **Create Database in Coolify**
   - Add MariaDB/MySQL service in Coolify
   - Note the service name (e.g., `mariadb`)
   - Create database and user
   - Set environment variables

3. **Import Database**
   ```bash
   mysql -h mariadb -u vtmoption_user -p vtmoption < backup.sql
   ```

### Step 2: Code Updates

1. Update `config.php` to use environment variables
2. Update `Database.php` to support port
3. Create `.env.example` file
4. Test locally with Docker Compose

### Step 3: Docker Configuration

1. Create `Dockerfile`
2. Create `docker-compose.yml` (for testing)
3. Build and test locally
4. Push to GitHub

### Step 4: Coolify Deployment

1. Connect GitHub repository to Coolify
2. Configure environment variables
3. Set up database service
4. Deploy application
5. Configure domain and SSL

### Step 5: Post-Deployment

1. Verify database connection
2. Test user authentication
3. Test trading functionality
4. Set up monitoring
5. Configure backups

---

## 9. BREAKING CHANGES & SOLUTIONS

### Breaking Change 1: Database Hostname

**Issue:** `localhost` won't work in Docker

**Solution:** Use service name (e.g., `mariadb`) as `DB_HOST`

### Breaking Change 2: Session Storage

**Issue:** Sessions may not persist across container restarts

**Solution:** Use Docker volumes or Redis for sessions

### Breaking Change 3: File Permissions

**Issue:** Container user may not have write access

**Solution:** Set proper ownership in Dockerfile:
```dockerfile
RUN chown -R www-data:www-data /var/www/html/storage
```

### Breaking Change 4: Error Logging

**Issue:** Error logs in container filesystem

**Solution:** Mount log directory as volume or use external logging

### Breaking Change 5: Cron Jobs

**Issue:** Cron jobs need container-specific paths

**Solution:** Use absolute paths in cron or external scheduler

---

## 10. RECOMMENDED DOCKERFILE

See `Dockerfile` in project root for complete implementation.

**Key Features:**
- PHP 8.1 with Apache
- Required extensions installed
- Proper file permissions
- Cron support
- Health check
- Security hardening

---

## 11. TESTING CHECKLIST

After migration, test:

- [ ] Database connection
- [ ] User registration
- [ ] User login
- [ ] Session persistence
- [ ] API endpoints
- [ ] Trading functionality
- [ ] Admin panel
- [ ] File uploads (if any)
- [ ] Error logging
- [ ] Cron jobs execution
- [ ] WebSocket connections (Deriv API)
- [ ] SSL/HTTPS
- [ ] Performance under load

---

## 12. ROLLBACK PLAN

If migration fails:

1. **Immediate Rollback:**
   - Revert DNS to Namecheap
   - Original site remains functional

2. **Database Rollback:**
   - Restore from backup
   - Verify data integrity

3. **Code Rollback:**
   - Revert to previous Git commit
   - Restore original `config.php`

---

## 13. PERFORMANCE OPTIMIZATIONS

### Recommended for Production

1. **Enable OPcache**
   ```php
   opcache.enable=1
   opcache.memory_consumption=128
   ```

2. **Use Redis for Sessions**
   - Faster than file-based
   - Better for scaling

3. **Database Connection Pooling**
   - Reuse connections
   - Reduce overhead

4. **CDN for Static Assets**
   - Offload CSS/JS/images
   - Reduce server load

5. **Enable Gzip Compression**
   - Already in `.htaccess`
   - Verify Nginx config if using

---

## 14. SECURITY CONSIDERATIONS

### Critical Security Updates

1. **✅ Environment Variables**: Move all secrets to environment
2. **✅ Encryption Key**: Generate new key for production
3. **✅ Database Credentials**: Use strong passwords
4. **✅ HTTPS**: Enable SSL in Coolify
5. **✅ Session Security**: Ensure secure cookies in production
6. **✅ Error Display**: Disable in production (already configured)
7. **✅ File Permissions**: Restrict access to sensitive files

### Security Headers

Already configured in `.htaccess`:
- X-Content-Type-Options
- X-XSS-Protection
- X-Frame-Options
- Content-Security-Policy

Ensure these are also set in Nginx if not using Apache.

---

## 15. MONITORING & LOGGING

### Recommended Monitoring

1. **Application Logs**
   - PHP error logs
   - Application-specific logs
   - Access logs

2. **Database Monitoring**
   - Connection pool status
   - Query performance
   - Slow query log

3. **System Metrics**
   - CPU usage
   - Memory usage
   - Disk I/O
   - Network traffic

4. **Application Metrics**
   - Active users
   - Trading activity
   - API response times
   - Error rates

### Log Aggregation

Consider using:
- Coolify's built-in logging
- External service (e.g., Logtail, Papertrail)
- Self-hosted (e.g., ELK stack)

---

## Conclusion

The application is well-structured and mostly compatible with Coolify. The main changes required are:

1. ✅ Environment variable configuration
2. ✅ Database connection updates
3. ✅ Dockerfile creation
4. ✅ Session storage consideration
5. ✅ Cron job configuration

With these changes, the migration should be smooth. The application architecture supports containerization well.

**Estimated Migration Time:** 2-4 hours (including testing)

**Risk Level:** Low to Medium (well-structured codebase)

**Recommended Approach:** Gradual migration with thorough testing at each step.

