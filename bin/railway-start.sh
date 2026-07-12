#!/usr/bin/env sh
# Single entrypoint, three roles. Railway runs the SAME image for every service and
# picks the role from $PROCESS, so the scheduler and the queue worker are real,
# supervised services — never backgrounded children of the web process. (That was
# v1's fatal bug: `schedule:work &` died silently and recurring billing stopped.)
#
#   PROCESS=web        (default)  the HTTP app
#   PROCESS=scheduler             mills:dispatch-due every 5 min + logs:prune daily
#   PROCESS=worker                drains the charges/mail/sync queues
#
# Migrations run once per release in bin/predeploy.sh, not here.
set -e

ROLE="${PROCESS:-web}"
echo "[start] role=${ROLE}"

case "$ROLE" in
  scheduler)
    exec php artisan schedule:work
    ;;
  worker)
    exec php artisan queue:work --queue=charges,mail,sync \
        --tries=3 --backoff=10 --max-time=3600 --sleep=3
    ;;
  web)
    exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
    ;;
  *)
    echo "[start] unknown PROCESS='${ROLE}' (expected web|scheduler|worker)" >&2
    exit 1
    ;;
esac
