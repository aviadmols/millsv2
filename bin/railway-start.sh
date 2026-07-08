#!/usr/bin/env sh
# Web process start (the worker + scheduler run as their own Railway services,
# each selecting its Procfile process). Migrations run in bin/predeploy.sh.
set -e

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
