<?php

namespace App\Jobs;

use App\Modules\MillsSubscriptions\Services\LegacyBulkImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One small CHUNK of the bulk import — never the whole list.
 *
 * The first version carried all 1,172 emails in a single job, which can never finish:
 * the database queue re-releases a reserved job after retry_after (90s), so a job that
 * runs twenty minutes is picked up again every minute and a half, runs in parallel
 * with itself, and after --tries=3 is stamped failed while the original is still going.
 * That is why the import "fell over" on every attempt, split in two or not.
 *
 * A chunk of a dozen emails finishes in well under the release window, a Shopify
 * hiccup costs one chunk instead of the whole run, and the import() underneath is
 * idempotent — so even the overlap the old shape suffered from would have written
 * nothing twice. The dispatcher (admin action) does the chunking.
 */
class ImportLegacyCustomersJob implements ShouldQueue
{
    use Queueable;

    /** Small enough that a chunk NEVER outlives the queue's release window. */
    public const CHUNK_SIZE = 12;

    public int $tries = 3;

    /** @param list<string> $emails */
    public function __construct(public array $emails)
    {
        $this->onQueue('sync');
    }

    public function handle(LegacyBulkImporter $importer): void
    {
        $importer->run($this->emails);
    }
}
