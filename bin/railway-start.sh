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
    # Same reasoning as the worker below: schedule:work is not meant to exit, but if
    # it ever does, ten restarts is all Railway will spend before leaving recurring
    # billing switched off. The loop outlives the budget; the home screen's CRON
    # check is what reports a scheduler that is up but failing.
    while true; do
      php artisan schedule:work || true
      echo "[scheduler] schedule:work exited — restarting"
      sleep 2
    done
    ;;
  worker)
    # --max-time makes queue:work exit CLEANLY (code 0) once an hour so the worker
    # runs with fresh memory. THIS LOOP is what restarts it — deliberately not
    # Railway.
    #
    # Railway's restart policy has a retry BUDGET (restartPolicyMaxRetries, max 10),
    # and an hourly recycle spends it: on 2026-09-23 the worker was deployed at
    # 11:29, recycled hourly, and after the tenth restart at 20:34 Railway stopped
    # restarting it. The queue then sat dead all night — 88 jobs, including the
    # morning's charges — and nothing said so except the home screen. A restart
    # policy is for crashes; a planned hourly recycle must not be charged to it.
    #
    # The container process is this shell, which never exits, so there is no clean
    # exit for Railway to count. `|| true` keeps the loop alive through a crash too
    # (set -e would otherwise kill the shell), and the sleep keeps a hard failure —
    # database unreachable, say — from spinning.
    while true; do
      php artisan queue:work --queue=charges,mail,sync \
          --tries=3 --backoff=10 --max-time=3600 --sleep=3 || true
      echo "[worker] queue:work exited — recycling"
      sleep 2
    done
    ;;
  web)
    exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
    ;;
  *)
    echo "[start] unknown PROCESS='${ROLE}' (expected web|scheduler|worker)" >&2
    exit 1
    ;;
esac
