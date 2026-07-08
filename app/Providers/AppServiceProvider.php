<?php

namespace App\Providers;

use App\Modules\MillsSubscriptions\Services\Sms\Sms019Sender;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SMS provider seam (D13) — 019 adapter behind the SmsSender contract.
        $this->app->bind(SmsSender::class, Sms019Sender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
