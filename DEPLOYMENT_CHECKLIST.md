# VTM Option - Quick Deployment Checklist

## Pre-Deployment

- [ ] Generate encryption key: `php -r "echo bin2hex(random_bytes(32));"`
- [ ] Update `config.php`:
  - [ ] `APP_ENV = 'production'`
  - [ ] `APP_URL = 'https://yourdomain.com'`
  - [ ] `DB_NAME = 'namecheap_database_name'`
  - [ ] `DB_USER = 'namecheap_database_user'`
  - [ ] `DB_PASS = 'namecheap_database_password'`
  - [ ] `ENCRYPTION_KEY = 'your_generated_key'`

## Namecheap cPanel Setup

- [ ] Create MySQL database
- [ ] Create MySQL database user
- [ ] Grant user permissions to database
- [ ] Set PHP version to 7.4+ (MultiPHP Manager)
- [ ] Enable required PHP extensions
- [ ] Install SSL certificate (AutoSSL)

## File Upload

- [ ] Upload all files to `public_html/`
- [ ] Set `storage/` permissions to 755
- [ ] Set `storage/sessions/` permissions to 755
- [ ] Verify `.htaccess` files are uploaded

## Database Setup

- [ ] Import `database/migrations/001_initial_schema.sql` via phpMyAdmin
- [ ] Verify all 10 tables are created
- [ ] **IMPORTANT:** Import `database/migrations/002_add_admin_column.sql` (adds is_admin column)
- [ ] Verify `is_admin` column exists in users table
- [ ] Create admin user: Run `php database/create_admin.php` or update user in database
- [ ] ✅ **FIXED:** Admin authentication now properly implemented

## Configuration

- [ ] Uncomment HTTPS redirect in `.htaccess` (if using SSL)
- [ ] Set `session.cookie_secure = 1` in `.htaccess` (if using SSL)
- [ ] Test database connection

## Cron Jobs Setup

- [ ] Add cron: `* * * * * /usr/bin/php /home/username/public_html/cron/trading_loop.php`
- [ ] (Optional) Add signal processor cron
- [ ] (Optional) Add contract monitor cron

## Testing

- [ ] Visit `https://yourdomain.com/check_requirements.php`
- [ ] Test homepage loads
- [ ] Test user registration
- [ ] Test user login
- [ ] Test dashboard
- [ ] Test admin panel access: `/admin/dashboard.php` (✅ admin-only access)
- [ ] Test API endpoints
- [ ] Verify cron jobs are running
- [ ] Check error logs

## Admin Setup

- [ ] **READ:** `ADMIN_SECURITY_IMPLEMENTATION.md` for implementation details
- [ ] Run database migration: `002_add_admin_column.sql`
- [ ] Create admin user: `php database/create_admin.php email@domain.com`
- [ ] OR update existing user: `UPDATE users SET is_admin = 1 WHERE email = 'user@domain.com'`
- [ ] Test admin login and admin panel access
- [ ] Test that non-admin users are blocked from admin pages
- [ ] (Optional) Configure IP whitelisting in `admin/.htaccess`

## Security

- [ ] Verify `config.php` is not accessible via browser
- [ ] Verify `.htaccess` blocks sensitive files
- [ ] Test SSL is working
- [ ] Verify security headers are present
- [ ] ✅ **FIXED:** Admin authentication implemented - verify it works
- [ ] Consider disabling user registration temporarily
- [ ] Monitor admin panel access logs

## Post-Deployment

- [ ] Monitor error logs for 24 hours
- [ ] Verify cron jobs execute successfully
- [ ] Test trading functionality
- [ ] (Optional) Remove `check_requirements.php`

---

**Quick Command Reference:**

Generate encryption key:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

Check PHP version:
```bash
php -v
```

Test database connection:
```php
<?php
require_once 'config.php';
$db = \App\Config\Database::getInstance();
$conn = $db->getConnection();
echo "Connected!";
```

