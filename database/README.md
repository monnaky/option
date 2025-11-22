# Database Setup Guide

## Overview

This directory contains the MySQL database schema and migration files for the VTM Option application.

## Files

- `migrations/001_initial_schema.sql` - Complete database schema
- `examples/database_usage.php` - Usage examples

## Quick Start

### 1. Create Database

```sql
CREATE DATABASE vtmoption CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Run Migration

```bash
mysql -u root -p vtmoption < migrations/001_initial_schema.sql
```

Or using MySQL command line:

```sql
USE vtmoption;
SOURCE migrations/001_initial_schema.sql;
```

### 3. Configure Environment

Create a `.env` file in the project root:

```env
DB_HOST=localhost
DB_NAME=vtmoption
DB_USER=root
DB_PASS=your_password
DB_CHARSET=utf8mb4
```

## Database Schema

### Tables

1. **users** - User accounts
2. **settings** - User trading settings
3. **trading_sessions** - Trading session tracking
4. **trades** - Trade records
5. **signals** - Trading signals
6. **sessions** - JWT token sessions
7. **admin_users** - Admin accounts
8. **admin_activity_logs** - Admin activity tracking
9. **api_call_logs** - API call logging
10. **system_settings** - System configuration

### Views

- `user_trading_stats` - User trading statistics
- `active_trading_sessions` - Active trading sessions

### Stored Procedures

- `ResetDailyStats()` - Reset daily statistics
- `CleanupExpiredSessions()` - Cleanup expired sessions

### Triggers

- `update_session_stats_after_trade` - Auto-update session stats

## Using the Database Class

```php
use App\Config\Database;

// Get instance
$db = Database::getInstance();

// Query
$users = $db->query("SELECT * FROM users WHERE is_active = :active", ['active' => true]);

// Insert
$id = $db->insert('users', [
    'email' => 'user@example.com',
    'password' => password_hash('password', PASSWORD_DEFAULT),
]);

// Update
$db->update('users', ['is_active' => false], ['id' => $id]);

// Delete
$db->delete('users', ['id' => $id]);
```

## Using Database Helper

```php
use App\Utils\DatabaseHelper;

$helper = new DatabaseHelper();

// Create user
$userId = $helper->createUser([
    'email' => 'user@example.com',
    'password' => password_hash('password', PASSWORD_DEFAULT),
]);

// Get user settings
$settings = $helper->getUserSettings($userId);

// Create trade
$tradeId = $helper->createTrade([
    'user_id' => $userId,
    'trade_id' => 'TRADE_123',
    'asset' => 'R_100',
    'direction' => 'RISE',
    'stake' => 1.00,
]);
```

## Indexes

All tables have appropriate indexes for:
- Primary keys
- Foreign keys
- Frequently queried fields
- Composite indexes for common query patterns

## Foreign Key Constraints

- `settings.user_id` → `users.id` (CASCADE)
- `trading_sessions.user_id` → `users.id` (CASCADE)
- `trades.user_id` → `users.id` (CASCADE)
- `trades.session_id` → `trading_sessions.id` (SET NULL)
- `sessions.user_id` → `users.id` (CASCADE)
- `admin_activity_logs.admin_user_id` → `admin_users.id` (CASCADE)
- `api_call_logs.user_id` → `users.id` (SET NULL)

## Data Types

- **IDs**: `INT UNSIGNED AUTO_INCREMENT`
- **Emails**: `VARCHAR(255)`
- **Passwords**: `VARCHAR(255)` (hashed)
- **Money**: `DECIMAL(10, 2)`
- **Booleans**: `BOOLEAN` (TINYINT(1))
- **Dates**: `DATETIME` or `DATE`
- **Timestamps**: `TIMESTAMP` with auto-update
- **JSON**: `JSON` type for flexible data

## Maintenance

### Reset Daily Stats

```sql
CALL ResetDailyStats();
```

### Cleanup Expired Sessions

```sql
CALL CleanupExpiredSessions();
```

### Manual Cleanup

```sql
-- Delete expired JWT sessions
DELETE FROM sessions WHERE expires_at < NOW();

-- Delete old trades (older than 1 year)
DELETE FROM trades WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Delete old logs (older than 6 months)
DELETE FROM api_call_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);
```

## Backup

```bash
# Full backup
mysqldump -u root -p vtmoption > backup_$(date +%Y%m%d).sql

# Structure only
mysqldump -u root -p --no-data vtmoption > schema.sql

# Data only
mysqldump -u root -p --no-create-info vtmoption > data.sql
```

## Restore

```bash
mysql -u root -p vtmoption < backup_20240101.sql
```

## Performance Tips

1. **Indexes**: All frequently queried fields are indexed
2. **Foreign Keys**: Use CASCADE for automatic cleanup
3. **Transactions**: Use for multiple related operations
4. **Prepared Statements**: Always used (automatic with Database class)
5. **Connection Pooling**: PDO handles this automatically

## Security

1. **Password Hashing**: Use `password_hash()` before storing
2. **SQL Injection**: Prevented by using prepared statements
3. **Input Validation**: Validate all input before database operations
4. **Access Control**: Use proper user permissions in MySQL

## Troubleshooting

### Connection Issues

```php
try {
    $db = Database::getInstance();
    $db->getConnection();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Check Connection

```sql
SELECT 1;
```

### Check Tables

```sql
SHOW TABLES;
```

### Check Table Structure

```sql
DESCRIBE users;
```

## Migration from MongoDB

See `MIGRATION_PLAN.md` for detailed migration instructions from MongoDB to MySQL.

