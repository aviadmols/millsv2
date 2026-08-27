<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use Throwable;

/**
 * Bring a LIST of Cardcom-era customers across from Shopify, by email.
 *
 * The single-customer import already exists (admin → "add a customer from Shopify");
 * this is the same act repeated, because 1,172 of them by hand is not a plan. Each
 * email is looked up in Shopify, then handed to LegacyCustomerImporter, which reads the
 * subscription off the customer's note and creates it BEHIND the card-update wall —
 * exactly right for these people, since we hold no PayMe token for a single one of them.
 *
 * Idempotent by construction: import() answers `already_has_subscription` and changes
 * nothing, so a run that dies at row 800 is simply run again.
 */
class LegacyBulkImporter
{
    public function __construct(
        private readonly ShopifyCustomerService $customers,
        private readonly LegacyCustomerImporter $importer,
    ) {}

    /**
     * @param  list<string>  $emails
     * @param  callable(string, string):void|null  $onEach  ($email, $outcome) — for CLI output
     * @return array<string, int> tally by outcome
     */
    public function run(array $emails, bool $dryRun = false, ?callable $onEach = null): array
    {
        $tally = ['imported' => 0, 'already' => 0, 'no_note' => 0, 'not_in_shopify' => 0, 'failed' => 0];

        foreach ($emails as $email) {
            $outcome = $this->one($email, $dryRun);
            $tally[$outcome]++;

            if ($onEach !== null) {
                $onEach($email, $outcome);
            }
        }

        if (! $dryRun) {
            SystemLog::info('admin', 'bulk import of Cardcom-era customers finished', $tally);
        }

        return $tally;
    }

    /** @return 'imported'|'already'|'no_note'|'not_in_shopify'|'failed' */
    private function one(string $email, bool $dryRun): string
    {
        try {
            $shopifyId = $this->shopifyIdFor($email);

            if ($shopifyId === null) {
                return 'not_in_shopify';
            }

            if ($dryRun) {
                return 'imported';   // what it WOULD do
            }

            $result = $this->importer->import($shopifyId, null, 'system:bulk-import');

            return match ($result['status']) {
                LegacyCustomerImporter::STATUS_IMPORTED => 'imported',
                LegacyCustomerImporter::STATUS_ALREADY_HAS_SUBSCRIPTION => 'already',
                LegacyCustomerImporter::STATUS_NO_NOTE => 'no_note',
                default => 'failed',
            };
        } catch (Throwable $e) {
            // One bad row must never end the run — 1,172 emails is far too long a walk to
            // start over because of a single Shopify hiccup.
            SystemLog::warning('admin', 'a customer in the bulk import could not be brought across', [
                'email' => $email,
                'message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * The Shopify customer id behind an email.
     *
     * Shopify's `email:` search is a SEARCH, not a lookup — it hands back neighbours — so
     * the address is compared back, exactly. Attaching a stranger's subscription to
     * somebody's account is far worse than importing nobody's, and nobody would notice.
     */
    private function shopifyIdFor(string $email): ?string
    {
        foreach ($this->customers->search('email:'.$email, 5) as $candidate) {
            if (strcasecmp(trim((string) ($candidate['email'] ?? '')), $email) === 0) {
                return (string) $candidate['id'];
            }
        }

        return null;
    }

    /**
     * The addresses in a pasted list: one per line, deduplicated, junk dropped.
     *
     * @return list<string>
     */
    public static function emailsIn(string $text): array
    {
        $emails = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $email = strtolower(trim($line));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;   // the header row, a stray tab, a typo'd address
            }

            $emails[$email] = true;
        }

        return array_keys($emails);
    }
}
