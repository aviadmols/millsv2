<?php

namespace App\Providers;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Http\Middleware\ShopifyEmbedded;
use App\Models\AppSetting;
use App\Models\CronRun;
use App\Models\Dog;
use App\Models\MailSetting;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Services\PayMe\PayMeGateway;
use App\Modules\MillsSubscriptions\Services\Sms\Sms019Sender;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use App\Observers\DogObserver;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Facades\Filament;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
        /*
         * The upcoming order IS the next charge — its total is what PayMe is asked for. So a
         * dog whose food changes without the order being rebuilt leaves the screen showing
         * one product, the customer charged for another, and the box holding a third.
         */
        Dog::observe(DogObserver::class);

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

        /*
         * Shopify's `locale` never decides this panel's language.
         *
         * Shopify appends the staff member's SHOPIFY-admin language to every embedded
         * load, and the switcher above prefers a `locale` query param over everything —
         * so opening this Hebrew-first system from an English Shopify admin rendered
         * every screen in English, with the Hebrew translations sitting right there.
         *
         * It has to be re-asserted HERE, not in a middleware: the switcher's middleware
         * carries no priority, so middleware sorting can place it after ours. Filament's
         * serving hook fires once the whole stack has run, which makes it the one place
         * guaranteed to get the last word.
         *
         * A language the person deliberately chose in the topbar lives in the session and
         * still wins. Only Shopify's guess about them is discarded.
         */
        Filament::serving(function (): void {
            $request = request();
            $configured = (string) $request->attributes->get(ShopifyEmbedded::CONFIGURED_LOCALE, '');

            if ($configured === '') {
                return;   // not an embedded load — nothing to override
            }

            $chosen = (string) ($request->hasSession() ? $request->session()->get('locale', '') : '');

            app()->setLocale($chosen !== '' ? $chosen : $configured);
        });

        // CRON audit log — record every scheduled task run (the v1 blind spot).
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            self::recordCronRun($event->task->getSummaryForDisplay(), 'completed', $event->runtime);
        });
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            self::recordCronRun($event->task->getSummaryForDisplay(), 'failed', null, $event->exception?->getMessage());
        });

        // Admin-managed settings (Settings page) applied over config at boot (D12).
        $this->applyRuntimeSettings();
    }

    /** Overlay DB-managed SMTP / PayMe / 019 settings on top of config. */
    private function applyRuntimeSettings(): void
    {
        try {
            $mail = MailSetting::query()->first();
            if ($mail && $mail->use_custom_smtp && $mail->smtp_host) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $mail->smtp_host,
                    'mail.mailers.smtp.port' => $mail->smtp_port ?: 587,
                    'mail.mailers.smtp.username' => $mail->smtp_username,
                    'mail.mailers.smtp.password' => $mail->smtp_password,
                    'mail.mailers.smtp.encryption' => $mail->smtp_encryption ?: 'tls',
                ]);
                if ($mail->from_address) {
                    config(['mail.from.address' => $mail->from_address, 'mail.from.name' => $mail->from_name ?: 'Mills']);
                }
            }

            foreach ([
                'payme.api_url' => 'payme_api_url',
                'payme.seller_id' => 'payme_seller_id',
                'payme.hosted_fields_api_key' => 'payme_hosted_fields_api_key',
                'sms.019.username' => 'sms_019_username',
                'sms.019.token' => 'sms_019_token',
                'sms.019.sender' => 'sms_019_sender',
            ] as $configKey => $settingKey) {
                $value = AppSetting::get($settingKey);
                if ($value !== null && $value !== '') {
                    config([$configKey => $value]);
                }
            }
        } catch (Throwable) {
            // DB not ready (e.g. during first migrate) — fall back to env config.
        }
    }

    private static function recordCronRun(string $command, string $status, ?float $runtime = null, ?string $output = null): void
    {
        try {
            CronRun::query()->create([
                'command' => $command,
                'status' => $status,
                'runtime_ms' => $runtime !== null ? (int) round($runtime * 1000) : null,
                'output' => $output,
                'ran_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let logging break the scheduler.
        }
    }
}
