#!/usr/bin/env sh
# Railway predeploy — runs once before the new release goes live.
# Fail closed: if migrations fail, the deploy is aborted.
#
# Only the WEB service migrates. The scheduler and worker services deploy from the
# same image, and three containers racing `migrate` on the same database buys
# nothing but lock contention.
set -e

ROLE="${PROCESS:-web}"

if [ "$ROLE" != "web" ]; then
  echo "[predeploy] role=${ROLE} — skipping migrations (web owns the schema)"
  exit 0
fi

echo "[predeploy] running migrations"
php artisan migrate --force

echo "[predeploy] linking storage"
php artisan storage:link || true

echo "[predeploy] done"
