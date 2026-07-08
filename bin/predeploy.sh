#!/usr/bin/env sh
# Railway predeploy — runs once before the new release goes live.
# Fail closed: if migrations fail, the deploy is aborted.
set -e

echo "[predeploy] running migrations"
php artisan migrate --force

echo "[predeploy] linking storage"
php artisan storage:link || true

echo "[predeploy] done"
