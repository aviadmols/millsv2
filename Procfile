web: php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
worker: php artisan queue:work --queue=charges,mail,sync --tries=3 --backoff=10 --max-time=3600
scheduler: php artisan schedule:work
