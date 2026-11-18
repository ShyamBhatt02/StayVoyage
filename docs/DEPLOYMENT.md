# StayVoyage — Production deployment (reference)

The current build runs locally on WAMP64. This guide is for a future Ubuntu 24.04 production server.

## 1. Server packages

```bash
sudo apt update && sudo apt upgrade -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y nginx mysql-server redis-server supervisor git unzip certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-gd php8.3-intl php8.3-bcmath
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo bash - && sudo apt install -y nodejs
```

PHP: set `memory_limit=256M`, `upload_max_filesize=8M`, `post_max_size=64M`, `expose_php=Off`, and enable OPcache (`opcache.enable=1`, `opcache.validate_timestamps=0`).

## 2. MySQL

```sql
CREATE DATABASE stayvoyage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stayvoyage'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON stayvoyage.* TO 'stayvoyage'@'localhost';
```

MySQL **8.0.16+** is required (CHECK constraints are enforced and protect inventory and night balances). Use `READ COMMITTED` or the default `REPEATABLE READ` isolation.

## 3. Redis

`/etc/redis/redis.conf`: `bind 127.0.0.1`, `requirepass <password>`, `maxmemory-policy noeviction` (queues must never be evicted). Set `REDIS_PASSWORD` in `.env`.

## 4. Application

```bash
sudo mkdir -p /var/www/stayvoyage && sudo chown deploy:www-data /var/www/stayvoyage
cd /var/www/stayvoyage && git clone <repo> .
cd backend && composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

Production `.env` essentials:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://stayvoyage.example
FRONTEND_URL=https://stayvoyage.example
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=stayvoyage.example
PAYMENT_DEFAULT_GATEWAY=razorpay        # sandbox is disabled automatically in production
RAZORPAY_KEY_ID=... RAZORPAY_KEY_SECRET=... RAZORPAY_WEBHOOK_SECRET=...
MAIL_MAILER=smtp  (your provider)
FILESYSTEM_DISK=public                   # or s3 later
```

```bash
php artisan migrate --force
php artisan storage:link
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # roles only — do NOT run demo seeders in production
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
cd ../frontend && npm ci && npm run build      # outputs to backend/public/app
```

Permissions:

```bash
sudo chown -R deploy:www-data /var/www/stayvoyage
sudo find backend/storage backend/bootstrap/cache -type d -exec chmod 775 {} \;
sudo find backend/storage backend/bootstrap/cache -type f -exec chmod 664 {} \;
```

Create the first super admin with `php artisan tinker` (attach role `super-admin`), then enable **Security → Enforce admin 2FA** in Settings.

## 5. Nginx

```nginx
server {
    listen 80;
    server_name stayvoyage.example;
    root /var/www/stayvoyage/backend/public;
    index index.php;
    client_max_body_size 64M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location /app/assets/ { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }
    location /storage/    { expires 30d; try_files $uri =404; }
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

SSL: `sudo certbot --nginx -d stayvoyage.example` (auto-renews via systemd timer).

## 6. Queue workers (Supervisor)

`/etc/supervisor/conf.d/stayvoyage-worker.conf`

```ini
[program:stayvoyage-worker]
command=php /var/www/stayvoyage/backend/artisan queue:work redis --sleep=2 --tries=3 --max-time=3600
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
user=deploy
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/stayvoyage/backend/storage/logs/worker.log
```

`sudo supervisorctl reread && sudo supervisorctl update`

## 7. Scheduler (cron)

```cron
* * * * * deploy cd /var/www/stayvoyage/backend && php artisan schedule:run >> /dev/null 2>&1
```

Runs: hold expiry (every minute), membership expiry (5 min), completion (03:10), reminders (09:00/10:00), ledger verification (02:30), failed-job pruning.

## 8. Payment webhooks

- Razorpay dashboard → Webhooks → `https://stayvoyage.example/api/v1/webhooks/razorpay`, events `payment.captured`, `payment.failed`; copy the secret to `RAZORPAY_WEBHOOK_SECRET`.
- Stripe → Developers → Webhooks → `https://stayvoyage.example/api/v1/webhooks/stripe`, events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`.

## 9. Deployment process (zero-downtime-ish)

```bash
cd /var/www/stayvoyage
php backend/artisan down --render="errors::503" --retry=30
git pull --ff-only
(cd backend && composer install --no-dev --optimize-autoloader && php artisan migrate --force \
  && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache)
(cd frontend && npm ci && npm run build)
php backend/artisan queue:restart
php backend/artisan up
```

Always run `php artisan test` in CI before deploying; migrations must be backwards compatible with the running code.

## 10. Backup & data safety

**What matters most:** users, memberships, membership_transactions, bookings, booking_rooms, payments, refunds, invoices, hotel_room_availability, user_trips.

| Backup | Schedule | Retention |
|---|---|---|
| Full logical dump (`mysqldump --single-transaction --routines --triggers stayvoyage \| gzip`) | Daily 02:00 | 30 daily, 12 monthly |
| MySQL binary logs (`binlog_expire_logs_seconds=604800`) for point-in-time recovery | Continuous | 7 days |
| `backend/storage/app` (uploads, invoices) via `rsync`/object storage | Daily | 30 days |
| Off-site copy (separate account/region), encrypted with `gpg` | Daily | 30 days |

```bash
# /usr/local/bin/stayvoyage-backup.sh
set -euo pipefail
TS=$(date +%F-%H%M)
mysqldump --single-transaction --quick --routines --triggers stayvoyage | gzip > /backups/db/stayvoyage-$TS.sql.gz
tar -czf /backups/files/storage-$TS.tgz -C /var/www/stayvoyage/backend storage/app
find /backups -type f -mtime +30 -delete
```

Restore drill: restore the latest dump into a staging database monthly and run `php artisan memberships:verify-ledger` — it must report no mismatches.

**Protection against accidental deletion:** bookings, payments, refunds, memberships, ledger rows and invoices use `restrictOnDelete` foreign keys; customer-facing entities use soft deletes; ledger rows are immutable in code; hotels/rooms with upcoming bookings can only be deactivated, not deleted. Every admin change is written to `audit_logs`.

## 11. Monitoring

Log channels (daily files): `payments`, `bookings`, `inventory`, `membership`, `auth`, `admin`. Ship them to your log stack, and alert on `CRITICAL` (amount mismatch, ledger mismatch) and on failed jobs (`php artisan queue:failed`). `/up` is the health-check endpoint.
