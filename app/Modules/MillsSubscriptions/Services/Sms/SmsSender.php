<?php

namespace App\Modules\MillsSubscriptions\Services\Sms;

/**
 * The SMS seam (ARCHITECTURE.md §6, D13). 019 is the chosen provider; its adapter
 * (Sms019Sender) implements this. Bound in AppServiceProvider so callers depend on
 * the contract, not the provider.
 */
interface SmsSender
{
    /** Returns true if the provider accepted the message for delivery. */
    public function send(string $phone, string $message): bool;
}
