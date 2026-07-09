<?php

namespace App\Providers;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Services\PayMe\PayMeGateway;
use App\Modules\MillsSubscriptions\Services\Sms\Sms019Sender;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SMS provider seam (D13) — 019 adapter behind the SmsSender contract.
        $this->app->bind(SmsSender::class, Sms019Sender::class);

        // PayMe as the single payment gateway (D5).
        $this->app->singleton(PaymeClient::class, fn () => PaymeClient::fromConfig());
        $this->app->bind(PaymentGateway::class, PayMeGateway::class);
    }

    public function boot(): void
    {
        // Behind Railway's TLS-terminating proxy the container sees HTTP; force
        // HTTPS URL generation so assets/links aren't Mixed-Content-blocked.
        if ($this->app->isProduction() || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Hebrew / English switcher in the admin topbar (D5, ARCHITECTURE.md §6).
        // Note: the package's flags() expects image URLs, not emoji — use labels.
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
            $switch->locales(['he', 'en'])
                ->labels(['he' => 'עברית', 'en' => 'English']);
        });
    }
}
