#!/usr/bin/env bash
# One-time set-up of a fresh Ubuntu 24.04 VPS for RightAlly Onboarding.
# Run as root:  sudo bash deploy/server-setup.sh onboard.rightally.io you@rightally.io
set -euo pipefail

DOMAIN="${1:?Usage: server-setup.sh <domain> <email-for-ssl-notices>}"
EMAIL="${2:?Usage: server-setup.sh <domain> <email-for-ssl-notices>}"
APP_USER="rightally"
APP_DIR="/var/www/rightally-onboard"
DB_NAME="rightally_onboard"
DB_USER="rightally"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"

echo "==> Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq nginx mysql-server supervisor git unzip curl ufw fail2ban certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd php8.3-intl php8.3-bcmath php8.3-zip php8.3-opcache composer
if ! command -v node >/dev/null || [[ "$(node -v | cut -d. -f1 | tr -d v)" -lt 22 ]]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -yq nodejs
fi

echo "==> Firewall"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo "==> App user and folders"
id -u "$APP_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$APP_USER"
usermod -aG www-data "$APP_USER"
mkdir -p "$APP_DIR" /var/backups/rightally
chown -R "$APP_USER":www-data "$APP_DIR" /var/backups/rightally

echo "==> MySQL database"
mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"

echo "==> PHP settings"
sed -i 's/^;\?expose_php.*/expose_php = Off/' /etc/php/8.3/fpm/php.ini
sed -i 's/^;\?upload_max_filesize.*/upload_max_filesize = 5M/' /etc/php/8.3/fpm/php.ini
sed -i 's/^;\?post_max_size.*/post_max_size = 8M/' /etc/php/8.3/fpm/php.ini
systemctl restart php8.3-fpm

echo "==> Nginx"
sed "s/__DOMAIN__/$DOMAIN/g; s#__APP_DIR__#$APP_DIR#g" "$(dirname "$0")/nginx.conf" > /etc/nginx/sites-available/rightally-onboard
ln -sf /etc/nginx/sites-available/rightally-onboard /etc/nginx/sites-enabled/rightally-onboard
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> Queue worker and scheduler"
sed "s#__APP_DIR__#$APP_DIR#g; s/__APP_USER__/$APP_USER/g" "$(dirname "$0")/supervisor.conf" > /etc/supervisor/conf.d/rightally-onboard.conf
supervisorctl reread && supervisorctl update || true
printf '%s\n' \
  "* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" \
  "30 2 * * * $APP_DIR/deploy/backup.sh >> /var/backups/rightally/backup.log 2>&1" | crontab -u "$APP_USER" -
chmod 700 /var/backups/rightally

echo "==> HTTPS certificate"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect || echo "Certbot failed: point DNS for $DOMAIN at this server, then run: certbot --nginx -d $DOMAIN"

cat <<DONE

Server ready. Save these database details for .env (shown once):
  DB_DATABASE=$DB_NAME
  DB_USERNAME=$DB_USER
  DB_PASSWORD=$DB_PASS

Next (as $APP_USER): follow "First deployment" in DEPLOYMENT.md.
DONE
