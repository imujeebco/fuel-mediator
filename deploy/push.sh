#!/usr/bin/env bash
# Copy the committed code (HEAD) to the server and apply it. Run from the repository root on the workstation:
#   SERVER=root@1.2.3.4 bash deploy/push.sh                  # first time: then run deploy/install.sh on the server
#   SERVER=root@1.2.3.4 bash deploy/push.sh --apply          # root VPS: composer, migrations, cache clear, restarts
#   SERVER=fuelne@host APP='~/fuel-mediator' SSH_PORT=22 bash deploy/push.sh --apply-user   # CWP/shared account
# Uncommitted changes are NOT deployed (git archive HEAD), so every deployed version is a commit.
# .env, runtime/ and vendor/ on the server are never touched by the archive (they are not in git).
set -euo pipefail
: "${SERVER:?set SERVER=user@host}"
APP=${APP:-/opt/fuel-mediator}
SSH="ssh -p ${SSH_PORT:-22}"
REV=$(git rev-parse --short HEAD)

echo "== shipping $REV to $SERVER:$APP"
git archive --format=tar HEAD | $SSH "$SERVER" "mkdir -p $APP && tar -x -C $APP && echo $REV > $APP/REVISION"

case "${1:-}" in
--apply)
    $SSH "$SERVER" bash -s <<EOF
set -euo pipefail
chown -R fuelmed:fuelmed $APP
cd $APP/core-engine
sudo -u fuelmed -H composer install --no-dev --optimize-autoloader --no-interaction --no-progress
sudo -u fuelmed php yii migrate:up --no-interaction
rm -rf runtime/cache
systemctl reload php8.3-fpm
systemctl restart fuel-mediator-collector.service
echo "deployed \$(cat $APP/REVISION)"
EOF
    ;;
--apply-user)
    # Shared hosting: no root, no services; cron restarts the collector every minute by itself.
    $SSH "$SERVER" bash -s <<EOF
set -euo pipefail
cd $APP/core-engine
cp ../deploy/apache/htaccess public/.htaccess
mkdir -p ../logs runtime
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
php yii migrate:up --no-interaction
rm -rf runtime/cache
echo "deployed \$(cat $APP/REVISION)"
EOF
    ;;
esac
