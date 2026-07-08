<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MillsStats extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(__('dashboard.customers'), Customer::query()->count()),

            Stat::make(
                __('dashboard.active_subscriptions'),
                Subscription::query()->where('status', SubscriptionStatus::ACTIVE->value)->count(),
            ),

            Stat::make(
                __('dashboard.due_today'),
                Subscription::query()
                    ->where('status', SubscriptionStatus::ACTIVE->value)
                    ->whereNotNull('next_charge_at')
                    ->whereDate('next_charge_at', '<=', now())
                    ->count(),
            ),

            Stat::make(
                __('dashboard.charges_30d'),
                PaymentLedger::query()
                    ->where('status', LedgerStatus::SUCCEEDED->value)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ),
        ];
    }
}
