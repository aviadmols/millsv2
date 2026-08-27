<?php

namespace App\Console\Commands;

use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\LegacyCustomerImporter;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Bring a LIST of Cardcom-era customers across, by email.
 *
 * The single-customer import already exists (admin → "add a customer from Shopify"); this
 * is the same act repeated over a file, because doing 900 of them by hand is not a plan.
 * Each one is looked up in Shopify by email, then handed to LegacyCustomerImporter, which
 * reads the subscription off the customer's note and creates it BEHIND the card-update
 * wall — exactly what these people need, since we hold no PayMe token for any of them.
 *
 * Two habits, both deliberate:
 *
 *  - --dry-run first. It reports what each email WOULD do, touching nothing, so a list
 *    can be checked against reality before it writes 900 rows.
 *  - It is idempotent. import() returns `already_has_subscription` and changes nothing,
 *    so a run that dies halfway can simply be run again.
 */
class ImportLegacyCustomersCommand extends Command
{
    protected $signature = 'mills:import-legacy-customers
        {file : A text file with one email per line}
        {--dry-run : Report what would happen and write nothing}
        {--limit=0 : Stop after this many emails (0 = all) — use it to try a few first}';

    protected $description = 'Import Cardcom-era customers from Shopify by email, each behind the card-update wall.';

    public function handle(ShopifyCustomerService $customers, LegacyCustomerImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $emails = $this->emails($path);

        if ($emails === []) {
            $this->error('The file holds no email addresses.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $emails = array_slice($emails, 0, $limit);
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s %d email(s)%s',
            $dryRun ? 'Checking' : 'Importing',
            count($emails),
            $dryRun ? ' — DRY RUN, nothing will be written' : '',
        ));

        $tally = ['imported' => 0, 'no_note' => 0, 'already' => 0, 'not_in_shopify' => 0, 'failed' => 0];

        foreach ($emails as $email) {
            try {
                $shopifyId = $this->shopifyIdFor($customers, $email);

                if ($shopifyId === null) {
                    $tally['not_in_shopify']++;
                    $this->line("  <fg=yellow>—</> {$email}: not found in Shopify");

                    continue;
                }

                if ($dryRun) {
                    $tally['imported']++;
                    $this->line("  <fg=cyan>?</> {$email}: found in Shopify ({$shopifyId}) — would import");

                    continue;
                }

                $result = $importer->import($shopifyId, null, 'system:bulk-import');

                match ($result['status']) {
                    LegacyCustomerImporter::STATUS_IMPORTED => $this->tallied($tally, 'imported', "<info>✓</info> {$email}: imported with its subscription"),
                    LegacyCustomerImporter::STATUS_ALREADY_HAS_SUBSCRIPTION => $this->tallied($tally, 'already', "<fg=gray>=</> {$email}: already here, untouched"),
                    LegacyCustomerImporter::STATUS_NO_NOTE => $this->tallied($tally, 'no_note', "<fg=yellow>!</> {$email}: customer added, but Shopify holds no subscription note"),
                    default => $this->tallied($tally, 'failed', "<error>✗</error> {$email}: {$result['status']}"),
                };
            } catch (Throwable $e) {
                // One bad row must never end the run — 900 emails is too long a walk to
                // start over because of a single Shopify hiccup.
                $tally['failed']++;
                $this->line("  <error>✗</error> {$email}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'imported=%d  already=%d  no_note=%d  not_in_shopify=%d  failed=%d',
            $tally['imported'], $tally['already'], $tally['no_note'], $tally['not_in_shopify'], $tally['failed'],
        ));

        if (! $dryRun) {
            SystemLog::info('admin', 'bulk import of Cardcom-era customers finished', $tally);
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $tally */
    private function tallied(array &$tally, string $key, string $line): void
    {
        $tally[$key]++;
        $this->line('  '.$line);
    }

    /**
     * The Shopify customer id behind an email.
     *
     * Shopify's search is fuzzy — `email:x@y.com` can return neighbours — so the address
     * is compared back, exactly and case-insensitively. Importing the wrong person's
     * subscription is far worse than importing nobody's.
     */
    private function shopifyIdFor(ShopifyCustomerService $customers, string $email): ?string
    {
        foreach ($customers->search('email:'.$email, 5) as $candidate) {
            if (strcasecmp(trim((string) ($candidate['email'] ?? '')), $email) === 0) {
                return (string) $candidate['id'];
            }
        }

        return null;
    }

    /** @return list<string> unique, lower-cased, in file order */
    private function emails(string $path): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $emails = [];

        foreach ($lines as $line) {
            $email = strtolower(trim($line));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $emails[$email] = true;   // the list arrives with duplicates; import each once
        }

        return array_keys($emails);
    }
}
