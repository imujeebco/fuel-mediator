#!/usr/bin/env bash
# Copy the committed code (HEAD) to the server and apply it. Run from the repository root on the workstation:
#   SERVER=root@1.2.3.4 bash deploy/push.sh            # first time: then run deploy/install.sh on the server
#   SERVER=root@1.2.3.4 bash deploy/push.sh --apply    # updates: composer, migrations, cache clear, restarts
# Uncommitted changes are NOT deployed (git archive HEAD), so every deployed version is a commit.
set -euo pipefail
: "${SERVER:?set SERVER=user@host}"
APP=/opt/fuel-mediator
REV=$(git rev-parse --short HEAD)

echo "== shipping $REV to $SERVER"
git archive --format=tar HEAD | ssh "$SERVER" "mkdir -p $APP && tar -x -C $APP && echo $REV > $APP/REVISION"

if [ "${1:-}" = "--apply" ]; then
    ssh "$SERVER" bash -s <<EOF
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
fi
