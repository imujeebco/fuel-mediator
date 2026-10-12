#!/usr/bin/env bash
# FuelPay Mediator — first-time install on Ubuntu 22.04/24.04 (run as root on the server).
#
# Prerequisites: the code is unpacked at /opt/fuel-mediator (deploy/push.sh does that), and DNS for MEDIATOR_HOST
# points at this server. Usage:
#   MEDIATOR_HOST=mediator.example.com CERTBOT_EMAIL=ops@example.com bash /opt/fuel-mediator/deploy/install.sh
#
# Idempotent: safe to run again. It never overwrites an existing core-engine/.env.
set -euo pipefail

: "${MEDIATOR_HOST:?set MEDIATOR_HOST}"
: "${CERTBOT_EMAIL:?set CERTBOT_EMAIL}"
APP=/opt/fuel-mediator
CORE=$APP/core-engine
PHPV=8.3

echo "== packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
if ! apt-cache show php$PHPV-fpm >/dev/null 2>&1; then
    apt-get install -y -q software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
fi
apt-get install -y -q nginx mysql-server unzip git curl certbot python3-certbot-nginx \
    php$PHPV-fpm php$PHPV-cli php$PHPV-mysql php$PHPV-bcmath php$PHPV-curl php$PHPV-mbstring php$PHPV-xml php$PHPV-intl
if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
timedatectl set-timezone UTC || true

echo "== user and directories"
id fuelmed >/dev/null 2>&1 || useradd --system --home "$APP" --shell /usr/sbin/nologin fuelmed
install -d -o fuelmed -g fuelmed /var/log/fuel-mediator "$CORE/runtime"
chown -R fuelmed:fuelmed "$APP"

echo "== database"
if [ ! -f /root/.fuel-mediator-db ]; then
    DBPASS=$(openssl rand -hex 24)
    mysql -e "CREATE DATABASE IF NOT EXISTS fuel_mediator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
              CREATE USER IF NOT EXISTS 'fuelmed'@'localhost' IDENTIFIED BY '$DBPASS';
              ALTER USER 'fuelmed'@'localhost' IDENTIFIED BY '$DBPASS';
              GRANT ALL PRIVILEGES ON fuel_mediator.* TO 'fuelmed'@'localhost'; FLUSH PRIVILEGES;"
    echo "$DBPASS" > /root/.fuel-mediator-db
    chmod 600 /root/.fuel-mediator-db
fi
DBPASS=$(cat /root/.fuel-mediator-db)

echo "== application environment"
if [ ! -f "$CORE/.env" ]; then
    cat > "$CORE/.env" <<EOF
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://$MEDIATOR_HOST

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fuel_mediator
DB_USER=fuelmed
DB_PASSWORD=$DBPASS

# Odoo: relay target and read-only user for station import / sales mirror (fill in)
ODOO_URL=https://fuelksa.odoo.com
ODOO_DB=iwesabe-fuel-main-11731627
ODOO_USER=
ODOO_PASSWORD=

RETENTION_DAYS=90

# Optional per-controller password overrides: PTS_PASSWORD_<odoo backend config id>=...
# (normally read from Odoo and stored encrypted, D23)

# Leave EMPTY until the go-live switch (docs/deployment-production.md). Setting it redirects devices' PTS traffic.
MEDIATOR_PUBLIC_URL=

# D21: report fills already paid by another sale as paid ("0" = off)
SETTLED_FILL_FILTER=1

# D23: encrypts controller passwords read from Odoo. Keep it secret and backed up; losing it only means
# running "Sync stations from Odoo" again.
MEDIATOR_SECRET_KEY=$(php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());')
EOF
    echo "   wrote $CORE/.env — fill in ODOO_USER and ODOO_PASSWORD, then run this script again"
fi
chown fuelmed:fuelmed "$CORE/.env"
chmod 600 "$CORE/.env"
if grep -qE '^(ODOO_USER|ODOO_PASSWORD)=$' "$CORE/.env"; then
    echo "!! $CORE/.env is incomplete; stopping before migrations"
    exit 1
fi

echo "== composer and migrations"
cd "$CORE"
sudo -u fuelmed -H composer install --no-dev --optimize-autoloader --no-interaction --no-progress
rm -rf "$CORE/runtime/cache"
sudo -u fuelmed php yii migrate:up --no-interaction

echo "== web server"
install -m 644 "$APP/deploy/php-fpm/fuel-mediator.conf" /etc/php/$PHPV/fpm/pool.d/fuel-mediator.conf
install -m 644 "$APP/deploy/nginx/fuel-mediator-php.conf" /etc/nginx/snippets/fuel-mediator-php.conf
sed "s/MEDIATOR_HOST/$MEDIATOR_HOST/g" "$APP/deploy/nginx/fuel-mediator.conf" > /etc/nginx/sites-available/fuel-mediator
ln -sf /etc/nginx/sites-available/fuel-mediator /etc/nginx/sites-enabled/fuel-mediator
rm -f /etc/nginx/sites-enabled/default
systemctl restart php$PHPV-fpm
nginx -t && systemctl reload nginx
if [ ! -d "/etc/letsencrypt/live/$MEDIATOR_HOST" ]; then
    certbot --nginx -d "$MEDIATOR_HOST" -m "$CERTBOT_EMAIL" --agree-tos --redirect --non-interactive
fi

echo "== background services"
install -m 644 "$APP"/deploy/systemd/fuel-mediator-*.service "$APP"/deploy/systemd/fuel-mediator-*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now fuel-mediator-collector.service fuel-mediator-sync.timer

echo "== done. Next: station registry (docs/deployment-production.md §3), then deploy/smoke-test.sh"
