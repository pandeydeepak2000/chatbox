# 🚀 ChatBox SaaS — Complete cPanel Live Deployment Guide

This guide details everything required to deploy **ChatBox SaaS** onto any cPanel shared hosting or cPanel VPS with zero downtime.

---

## 📋 Pre-Deployment Checklist

| Requirement | Recommended Setting |
| :--- | :--- |
| **PHP Version** | PHP 8.2 or PHP 8.3 |
| **Required PHP Extensions** | `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `tokenizer`, `xml` |
| **Database** | MySQL 5.7+ or MariaDB 10.3+ |
| **SSL Certificate** | Let's Encrypt / AutoSSL (HTTPS enabled) |

---

## Step 1: Create MySQL Database in cPanel

1. Log in to your **cPanel**.
2. Navigate to **Databases** > **MySQL® Database Wizard**.
3. Create a new database: e.g. `youruser_chatbox`.
4. Create a database user: e.g. `youruser_dbuser` and set a strong password.
5. In **Add User to Database**, check **ALL PRIVILEGES** and click **Make Changes**.

---

## Step 2: Upload Files to cPanel

### Option A: Subdomain Setup (Recommended, e.g. `chat.yourdomain.com`)
1. In cPanel > **Domains** > **Create A New Domain** / **Subdomains**.
2. Create subdomain `chat.yourdomain.com`.
3. Set its **Document Root** to: `public_html/chatbox/public` (Pointing directly to the `public/` directory).
4. Upload your ChatBox zip file to `public_html/chatbox` and extract it.

### Option B: Main Domain Setup (`public_html`)
1. Upload and extract all files inside `public_html`.
2. The root `.htaccess` already included in this repository will automatically route traffic to the `public/` directory safely while blocking access to `.env` and system files.

---

## Step 3: Configure `.env` File

1. In cPanel **File Manager**, enable **Show Hidden Files (dotfiles)** in Settings.
2. Copy `.env.example` to `.env` (or edit existing `.env`).
3. Update the following key values:

```env
APP_NAME="ChatBox SaaS"
APP_ENV=production
APP_KEY=base64:33Y8D2185Tj90qRj51E2b4aW8FfK3hM9y1X0lVz4A7Q=
APP_DEBUG=false
APP_URL=https://chat.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=youruser_chatbox
DB_USERNAME=youruser_dbuser
DB_PASSWORD=YourPasswordHere

CACHE_STORE=file
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

---

## Step 4: Run Migrations & Import Database

### If you have SSH / Terminal access in cPanel:
Open the cPanel **Terminal** and run:
```bash
cd public_html/chatbox
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### If you do NOT have SSH access:
1. Open **phpMyAdmin** in cPanel.
2. Select your database `youruser_chatbox`.
3. Click **Import** and upload the database SQL dump file.

---

## Step 5: Set Directory Permissions

In cPanel **File Manager** (or Terminal), ensure write permissions are set:
- `storage/` ➔ **775** (Recursively)
- `bootstrap/cache/` ➔ **775** (Recursively)

```bash
chmod -R 775 storage bootstrap/cache
```

---

## Step 6: Real-Time WebSockets vs Polling on cPanel

ChatBox is designed with **Dual-Engine Realtime Architecture**:
1. **Fallback Polling (Built-in)**: If your shared cPanel hosting does not allow custom background daemon ports (like port 8080), **ChatBox automatically falls back to smart 3-second heartbeat polling**. It works 100% out of the box with zero additional configuration!
2. **Dedicated VPS / Daemon (Optional)**: If your cPanel VPS has background process support, you can run Laravel Reverb:
   ```bash
   php artisan reverb:start --host=0.0.0.0 --port=8080
   ```

---

## Step 7: Setup Background Cron Job in cPanel

1. Go to cPanel > **Cron Jobs**.
2. Select **Once Per Minute** (`* * * * *`).
3. Enter Command:
   ```bash
   /usr/local/bin/php /home/yourcpaneluser/public_html/chatbox/artisan schedule:run >> /dev/null 2>&1
   ```
4. Click **Add New Cron Job**.

---

## ✅ Post-Deployment Verification

1. Open `https://chat.yourdomain.com` ➔ Super Admin login screen will appear.
2. Log in with Super Admin credentials:
   - **Email**: `ambitionmanagements@gmail.com`
   - **Password**: `password`
3. Open `https://chat.yourdomain.com/demo.html` ➔ Verify the live ChatBox demo page and test the widget.
