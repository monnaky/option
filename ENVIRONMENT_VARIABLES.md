# Environment Variables Reference
## Required Environment Variables for Coolify Deployment

This document lists all environment variables that should be set in Coolify for proper application configuration.

---

## Required Variables

### Application Configuration

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `APP_ENV` | Application environment | `production` | Yes |
| `APP_URL` | Full application URL | `https://yourdomain.com` | Yes |
| `APP_TIMEZONE` | Application timezone | `UTC` | No (default: UTC) |

### Database Configuration

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `DB_HOST` | Database hostname | `mariadb` (service name) | Yes |
| `DB_PORT` | Database port | `3306` | No (default: 3306) |
| `DB_NAME` | Database name | `vtmoption` | Yes |
| `DB_USER` | Database username | `vtmoption_user` | Yes |
| `DB_PASS` | Database password | `secure_password_123` | Yes |
| `DB_CHARSET` | Database charset | `utf8mb4` | No (default: utf8mb4) |

### Security Configuration

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `ENCRYPTION_KEY` | 64-character hex encryption key | `7f3a9b2c8d4e1f6a...` | Yes |

### Deriv API Configuration (Optional)

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `DERIV_APP_ID` | Deriv application ID | `105326` | No (default: 105326) |
| `DERIV_WS_HOST` | Deriv WebSocket host | `ws.derivws.com` | No (default: ws.derivws.com) |

### Optional Configuration

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `LOG_PATH` | Custom error log path | `/var/log/php/error.log` | No |

---

## Setting Variables in Coolify

1. Navigate to your application in Coolify dashboard
2. Go to "Environment Variables" section
3. Add each variable with its value
4. Save and redeploy application

---

## Example Configuration

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
DB_PASS=your_secure_password_here
DB_CHARSET=utf8mb4

# Security
ENCRYPTION_KEY=your_64_character_hex_key_here

# Deriv API (optional)
DERIV_APP_ID=105326
DERIV_WS_HOST=ws.derivws.com
```

---

## Generating Encryption Key

To generate a new encryption key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

This will output a 64-character hexadecimal string that you can use for `ENCRYPTION_KEY`.

---

## Database Host Configuration

**Important:** In Coolify, the database service name becomes the hostname.

- If you create a MariaDB service named `mariadb`, use `DB_HOST=mariadb`
- If you create a MySQL service named `mysql`, use `DB_HOST=mysql`
- The service name is shown in Coolify's database service configuration

---

## Security Notes

1. **Never commit** `.env` files or actual environment variable values to Git
2. **Use strong passwords** for database credentials
3. **Generate unique encryption keys** for each environment
4. **Rotate keys periodically** in production
5. **Use Coolify's secret management** for sensitive values

---

## Validation

After setting environment variables, verify they're loaded correctly:

1. Check application logs for configuration errors
2. Test database connection
3. Verify application loads without errors
4. Test user authentication (sessions depend on encryption key)

