# Coolify Deployment Checklist
## Step-by-Step Migration Guide

Use this checklist to ensure a smooth migration from Namecheap/cPanel to Coolify VPS.

---

## Pre-Migration Preparation

### 1. Backup Current Deployment
- [ ] Export database from Namecheap cPanel
  ```bash
  # Via phpMyAdmin or command line
  mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
  ```
- [ ] Download all files from Namecheap
- [ ] Document current configuration values
- [ ] Test backup restoration locally (optional but recommended)

### 2. Generate New Security Keys
- [ ] Generate new encryption key:
  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```
- [ ] Save the key securely (you'll need it for Coolify)

### 3. Code Updates
- [ ] Verify `config.php` uses environment variables (already updated)
- [ ] Verify `Database.php` supports port configuration (already updated)
- [ ] Review `.env.example` file
- [ ] Commit all changes to GitHub

---

## Coolify Setup

### 4. Create Database Service
- [ ] Log into Coolify dashboard
- [ ] Navigate to your project
- [ ] Click "Add Service" → "Database"
- [ ] Select MariaDB or MySQL
- [ ] Note the service name (e.g., `mariadb`)
- [ ] Create database: `vtmoption`
- [ ] Create database user: `vtmoption_user`
- [ ] Set a strong password
- [ ] Save database credentials securely

### 5. Import Database
- [ ] Access database via Coolify's database management tool
- [ ] Or use command line:
  ```bash
  mysql -h mariadb -u vtmoption_user -p vtmoption < backup_YYYYMMDD.sql
  ```
- [ ] Verify tables are imported correctly
- [ ] Test database connection

### 6. Configure Application Service
- [ ] In Coolify, create new application
- [ ] Connect GitHub repository
- [ ] Select branch (usually `main` or `master`)
- [ ] Set build pack to "Dockerfile"
- [ ] Verify Dockerfile is detected

### 7. Set Environment Variables
Configure these in Coolify's environment settings:

**Application:**
- [ ] `APP_ENV=production`
- [ ] `APP_URL=https://yourdomain.com` (update with your actual domain)
- [ ] `APP_TIMEZONE=UTC`

**Database:**
- [ ] `DB_HOST=mariadb` (use your database service name)
- [ ] `DB_PORT=3306`
- [ ] `DB_NAME=vtmoption`
- [ ] `DB_USER=vtmoption_user`
- [ ] `DB_PASS=your_database_password` (from step 4)

**Security:**
- [ ] `ENCRYPTION_KEY=your_generated_key` (from step 2)

**Deriv API (Optional):**
- [ ] `DERIV_APP_ID=105326` (default, can omit)
- [ ] `DERIV_WS_HOST=ws.derivws.com` (default, can omit)

**Optional:**
- [ ] `LOG_PATH=/var/log/php/error.log` (optional)

### 8. Configure Domain & SSL
- [ ] Add your domain in Coolify
- [ ] Configure DNS records (A record or CNAME)
- [ ] Enable SSL/TLS (Let's Encrypt)
- [ ] Verify SSL certificate is active

### 9. Deploy Application
- [ ] Trigger deployment in Coolify
- [ ] Monitor build logs for errors
- [ ] Wait for deployment to complete
- [ ] Check application health status

---

## Post-Deployment Verification

### 10. Database Connection Test
- [ ] Access application URL
- [ ] Check for database connection errors
- [ ] Verify database queries work
- [ ] Test user registration
- [ ] Test user login

### 11. Application Functionality
- [ ] Test homepage loads correctly
- [ ] Test user registration
- [ ] Test user login
- [ ] Test dashboard access
- [ ] Test trading functionality
- [ ] Test API endpoints
- [ ] Test admin panel (if applicable)

### 12. Session & Storage
- [ ] Verify sessions persist (login stays active)
- [ ] Check `storage/sessions/` directory is writable
- [ ] Test file uploads (if any)
- [ ] Verify error logs are being written

### 13. Cron Jobs
- [ ] Verify cron service is running in container
- [ ] Check cron logs: `/var/log/cron.log`
- [ ] Test trading loop execution
- [ ] Monitor for any cron errors

### 14. Performance & Monitoring
- [ ] Check application response times
- [ ] Monitor resource usage (CPU, memory)
- [ ] Set up monitoring alerts (optional)
- [ ] Review error logs for issues

---

## Troubleshooting

### Database Connection Issues
**Symptoms:** "Database connection failed" errors

**Solutions:**
1. Verify `DB_HOST` matches your database service name
2. Check database credentials are correct
3. Ensure database service is running
4. Verify network connectivity between containers
5. Check database user has proper permissions

### Session Issues
**Symptoms:** Users logged out frequently, sessions not persisting

**Solutions:**
1. Verify `storage/sessions/` directory is writable
2. Check file permissions: `chmod 755 storage/sessions`
3. Consider using Redis for sessions (advanced)

### Cron Job Issues
**Symptoms:** Trading loop not executing

**Solutions:**
1. Check cron service is running: `docker exec container_name service cron status`
2. View cron logs: `docker exec container_name tail -f /var/log/cron.log`
3. Test cron manually: `docker exec container_name php /var/www/html/cron/trading_loop.php`
4. Verify file paths in cron job

### File Permission Issues
**Symptoms:** "Permission denied" errors

**Solutions:**
1. Check file ownership: `ls -la storage/`
2. Fix permissions: `chmod -R 755 storage/`
3. Fix ownership: `chown -R www-data:www-data storage/`

### .htaccess Not Working
**Symptoms:** URL rewriting not working, 404 errors

**Solutions:**
1. Verify Apache mod_rewrite is enabled (included in Dockerfile)
2. Check Apache configuration allows .htaccess
3. Review Apache error logs
4. Consider converting to Nginx config (advanced)

---

## Rollback Plan

If migration fails:

1. **Immediate Rollback:**
   - Revert DNS to Namecheap
   - Original site remains functional

2. **Database Rollback:**
   - Restore database from backup
   - Verify data integrity

3. **Code Rollback:**
   - Revert to previous Git commit
   - Restore original configuration

---

## Post-Migration Tasks

### 15. Update DNS
- [ ] Point domain to Coolify (if not done in step 8)
- [ ] Wait for DNS propagation (up to 48 hours)
- [ ] Verify domain resolves correctly

### 16. Final Testing
- [ ] Test all user flows
- [ ] Test trading functionality end-to-end
- [ ] Test admin functions
- [ ] Load test (optional)

### 17. Documentation
- [ ] Document new deployment process
- [ ] Update team documentation
- [ ] Note any environment-specific configurations

### 18. Monitoring Setup
- [ ] Set up application monitoring
- [ ] Configure error alerts
- [ ] Set up uptime monitoring
- [ ] Schedule regular backups

---

## Success Criteria

Migration is successful when:
- ✅ Application loads without errors
- ✅ Database connection works
- ✅ User authentication works
- ✅ Trading functionality works
- ✅ Cron jobs execute correctly
- ✅ SSL certificate is active
- ✅ No critical errors in logs
- ✅ Performance is acceptable

---

## Support & Resources

- **Coolify Documentation:** https://coolify.io/docs
- **Docker Documentation:** https://docs.docker.com
- **PHP Docker Images:** https://hub.docker.com/_/php

---

## Notes

- Keep Namecheap hosting active until migration is fully verified
- Test thoroughly before switching DNS
- Monitor closely for first 24-48 hours after migration
- Have rollback plan ready

---

**Last Updated:** Based on code review and migration analysis  
**Estimated Migration Time:** 2-4 hours (including testing)  
**Risk Level:** Low to Medium

