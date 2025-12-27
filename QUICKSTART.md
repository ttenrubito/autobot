# Quick Reference Guide

## 🔐 Default Credentials

### Customer Portal
- URL: `http://localhost/autobot/public/`
- Email: `demo@aiautomation.com`
- Password: `demo1234`

### Admin Panel
- URL: `http://localhost/autobot/admin/login.html`
- Username: `admin`
- Password: `admin123`

⚠️ **Change these immediately in production!**

## 📊 Key URLs

| Service | URL |
|---------|-----|
| Customer Dashboard | `/autobot/public/dashboard.html` |
| API Documentation | `/autobot/public/api-docs.html` |
| Usage Statistics | `/autobot/public/usage.html` |
| Admin Dashboard | `/autobot/admin/dashboard.html` |
| Health Check | `/autobot/api/health.php` |

## 🔑 API Key

Get your API key from: **Customer Portal → API Docs**

Format: `ak_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

## 🚀 Quick Start Commands

```bash
# Start XAMPP
sudo /opt/lampp/lampp start

# Check MySQL
sudo /opt/lampp/lampp mysql status

# View logs
tail -f /opt/lampp/htdocs/autobot/logs/app-$(date +%Y-%m-%d).log

# Test API health
curl http://localhost/autobot/api/health.php
```

## 📁 Important Files

| File | Purpose |
|------|---------|
| `.env.example` | Environment variables template |
| `config-cloud.php` | Configuration |
| `README.md` | Full documentation |
| `DEPLOYMENT.md` | Deployment guide |
| `API_TESTING.md` | n8n integration examples |

## 🛠️ Common Tasks

### Reset Admin Password

```sql
-- Generate new password hash
php -r "echo password_hash('newpassword', PASSWORD_DEFAULT);"

-- Update in database
UPDATE admin_users 
SET password_hash = 'HASH_FROM_ABOVE' 
WHERE username = 'admin';
```

### Regenerate API Key

1. Login to Customer Portal
2. Go to API Docs page
3. Click "Regenerate" button

### Check Rate Limits

```sql
SELECT identifier, action, COUNT(*) as attempts, MAX(created_at) as last_attempt
FROM rate_limits
WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
GROUP BY identifier, action;
```

### Clear Rate Limit Lock

```sql
DELETE FROM rate_limits 
WHERE identifier = 'IP_ADDRESS' 
AND action = 'login';
```

## 🔥 Troubleshooting

### Can't Login - Rate Limited

Wait 5 minutes or clear via SQL:
```sql
DELETE FROM rate_limits WHERE identifier = 'YOUR_IP';
```

### API Returns 401

- Check API key is correct
- Check API key is active
- Check user account is active

### Charts Not Showing

- Check sample data is loaded
- Check browser console for errors
- Verify Chart.js is loading

### Google API Not Working

- Check environment variables are set
- Verify `GOOGLE_VISION_API_KEY` and `GOOGLE_LANGUAGE_API_KEY`
- Check logs for errors

## 📈 Monitoring

```bash
# Watch logs in real-time
tail -f logs/app-*.log | jq

# Check database
mysql -u root autobot -e "SHOW TABLES;"

# Test endpoints
curl http://localhost/autobot/api/health.php
curl http://localhost/autobot/api/dashboard/stats.php
```

## 🎯 Next Steps After Installation

1. ✅ Change default passwords
2. ✅ Set up Google API keys
3. ✅ Configure allowed origins in `.env`
4. ✅ Set up backups
5. ✅ Configure monitoring
6. ✅ Test n8n integration
7. ✅ Review security settings

## 📞 Support

- Full README: `/autobot/README.md`
- Deployment Guide: `/autobot/DEPLOYMENT.md`
- API Testing: `/autobot/API_TESTING.md`
- Professional Analysis: Brain artifacts

---

**Version:** 1.0.0  
**Last Updated:** 2025-12-10
