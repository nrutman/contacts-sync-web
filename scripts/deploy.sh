#!/usr/bin/env bash
# Production deploy steps — run after pulling new code on the prod server.
#
# Invoked automatically by .githooks/post-merge after `git pull` / `git merge`,
# gated on APP_ENV=prod. Can also be run manually: `./scripts/deploy.sh`.

set -euo pipefail

cd "$(dirname "$0")/.."

# Symfony reads APP_ENV from .env.local at runtime, but the shell (and git
# hooks) don't — so fall back to parsing it out when it's not in the env.
if [ -z "${APP_ENV:-}" ] && [ -f .env.local ]; then
    APP_ENV=$(grep -E '^\s*APP_ENV\s*=' .env.local | tail -1 \
        | sed -E "s/^\s*APP_ENV\s*=\s*//; s/^[\"']//; s/[\"']$//")
fi

if [ "${APP_ENV:-}" != "prod" ]; then
    echo "[deploy] APP_ENV is '${APP_ENV:-unset}', not 'prod' — refusing to run."
    echo "[deploy] Set APP_ENV=prod (in .env.local or the shell) before running deploy."
    exit 1
fi

echo "[deploy] Installing PHP dependencies (no dev, optimized autoloader)..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "[deploy] Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[deploy] Building (clears cache, compiles assets)..."
composer run-script build

echo
echo "[deploy] Done."
echo "[deploy] If the messenger worker (messenger:consume) is running under"
echo "[deploy] systemd / supervisor / similar, restart it now so it picks up"
echo "[deploy] the new code. Example:"
echo "[deploy]   sudo systemctl restart contacts-sync-worker"
