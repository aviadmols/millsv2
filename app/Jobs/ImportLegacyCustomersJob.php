<?php

namespace App\Jobs;

use App\Modules\MillsSubscriptions\Services\LegacyBulkImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The bulk import, on the worker.
 *
 * 1,172 emails is 1,172 Shopify lookups — minutes of work that has no business inside a
 * browser request. The admin screen hands the list over and says so; the result lands in
 * the system log, and the customers appear as they are created.
 *
 * The list is carried as data rather than a file path: the web container and the worker
 * do not share a disk on Railway, so a file written by one is invisible to the other.
 */
class ImportLegacyCustomersJob implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $emails */
    public function __construct(public array $emails)
    {
        $this->onQueue('sync');
    }

    /**
     * Long by nature — every email is a round trip to Shopify. An hour is far more than
     * the whole list needs and far less than forever.
     */
    public int $timeout = 3600;

    public function handle(LegacyBulkImporter $importer): void
    {
        $importer->run($this->emails);
    }
}
