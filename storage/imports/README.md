# Legacy customer lists

Email lists for `mills:import-legacy-customers` — the Cardcom-era customers being
brought across from Shopify.

Each file is one email per line. Run a dry run first:

    php artisan mills:import-legacy-customers storage/imports/<file>.txt --dry-run --limit=3

Then the same command without `--dry-run`. It is idempotent: a customer already
here is reported and left alone, so an interrupted run is simply run again.
