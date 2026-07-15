<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Models\MailSetting;
use App\Models\ShopifyConnection;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;

/**
 * The one place to configure everything the system needs to run: SMTP (D12),
 * PayMe (D5), and 019 SMS (D13). SMTP is stored on mail_settings (password
 * encrypted); PayMe/019 on app_settings. AppServiceProvider applies these over
 * config at boot.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('settings.title');
    }

    public function getTitle(): string
    {
        return __('settings.title');
    }

    public function mount(): void
    {
        $mail = MailSetting::current();
        $conn = ShopifyConnection::current();

        $this->form->fill([
            'shopify_shop_domain' => $conn?->shop_domain ?: config('shopify.shop_domain'),
            'shopify_installed_at' => $conn?->installed_at?->toDayDateTimeString() ?: '—',

            'use_custom_smtp' => (bool) $mail->use_custom_smtp,
            'smtp_host' => $mail->smtp_host,
            'smtp_port' => $mail->smtp_port,
            'smtp_encryption' => $mail->smtp_encryption,
            'smtp_username' => $mail->smtp_username,
            'smtp_password' => $mail->smtp_password,
            'from_name' => $mail->from_name,
            'from_address' => $mail->from_address,

            'payme_api_url' => AppSetting::get('payme_api_url'),
            'payme_seller_id' => AppSetting::get('payme_seller_id'),
            'payme_hosted_fields_api_key' => AppSetting::get('payme_hosted_fields_api_key'),

            'sms_019_username' => AppSetting::get('sms_019_username'),
            'sms_019_token' => AppSetting::get('sms_019_token'),
            'sms_019_sender' => AppSetting::get('sms_019_sender'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('settings.shopify'))
                    ->description(__('settings.shopify_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('shopify_shop_domain')->label(__('settings.shop_domain'))->disabled()->dehydrated(false),
                        TextInput::make('shopify_installed_at')->label(__('settings.installed_at'))->disabled()->dehydrated(false),
                    ]),

                Section::make(__('settings.smtp'))
                    ->description(__('settings.smtp_help'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('use_custom_smtp')->label(__('settings.use_custom_smtp'))->columnSpanFull(),
                        TextInput::make('smtp_host')->label('Host'),
                        TextInput::make('smtp_port')->label('Port')->numeric(),
                        TextInput::make('smtp_encryption')->label('Encryption')->placeholder('tls / ssl'),
                        TextInput::make('smtp_username')->label('Username'),
                        TextInput::make('smtp_password')->label('Password')->password()->revealable(),
                        TextInput::make('from_name')->label(__('settings.from_name')),
                        TextInput::make('from_address')->label(__('settings.from_address'))->email(),
                    ]),

                Section::make(__('settings.payme'))
                    ->description(__('settings.payme_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('payme_api_url')->label('API URL')->url(),
                        TextInput::make('payme_seller_id')->label('Seller ID'),
                        TextInput::make('payme_hosted_fields_api_key')->label('Hosted Fields API key')->columnSpanFull(),
                    ]),

                Section::make(__('settings.sms'))
                    ->description(__('settings.sms_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('sms_019_username')->label('019 Username'),
                        TextInput::make('sms_019_token')->label('019 Token')->password()->revealable(),
                        TextInput::make('sms_019_sender')->label(__('settings.sms_sender')),
                    ]),
            ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();

        $mail = MailSetting::current();
        $mail->fill([
            'use_custom_smtp' => (bool) ($d['use_custom_smtp'] ?? false),
            'smtp_host' => $d['smtp_host'] ?? null,
            'smtp_port' => $d['smtp_port'] ?? null,
            'smtp_encryption' => $d['smtp_encryption'] ?? null,
            'smtp_username' => $d['smtp_username'] ?? null,
            'smtp_password' => $d['smtp_password'] ?? null,
            'from_name' => $d['from_name'] ?? null,
            'from_address' => $d['from_address'] ?? null,
        ])->save();

        foreach (['payme_api_url', 'payme_seller_id', 'payme_hosted_fields_api_key', 'sms_019_username', 'sms_019_token', 'sms_019_sender'] as $key) {
            AppSetting::put($key, $d[$key] ?? null);
        }

        Notification::make()->title(__('settings.saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label(__('settings.save'))->submit('save'),
            Action::make('connectShopify')
                ->label(__('settings.connect_shopify'))
                ->icon(Heroicon::OutlinedLink)
                ->color('primary')
                ->url(route('shopify.install', ['shop' => config('shopify.shop_domain')]))
                ->openUrlInNewTab(),
            Action::make('testShopify')
                ->label(__('settings.test_shopify'))
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function (): void {
                    $client = app(ShopifyAdminClient::class);
                    if (! $client->isConnected()) {
                        Notification::make()->title(__('settings.shopify_not_connected'))->warning()->persistent()->send();

                        return;
                    }

                    $shop = $client->restGet('shop.json');
                    $domain = $shop['shop']['myshopify_domain'] ?? null;

                    if ($domain !== null) {
                        Notification::make()->title(__('settings.shopify_ok', ['shop' => $domain]))->success()->send();
                    } else {
                        Notification::make()->title(__('settings.shopify_invalid'))->danger()->persistent()->send();
                    }
                }),
            Action::make('testPayme')
                ->label(__('settings.test_payme'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->color('gray')
                ->action(function (): void {
                    // Probe the values that are on screen, saved or not — the admin
                    // expects the visible values to be the ones tested.
                    $apiUrl = rtrim(trim((string) ($this->data['payme_api_url'] ?? '')), '/');
                    $sellerId = trim((string) ($this->data['payme_seller_id'] ?? ''));
                    if ($apiUrl === '' || $sellerId === '') {
                        Notification::make()->title(__('settings.payme_missing'))->warning()->send();

                        return;
                    }

                    $client = new PaymeClient($apiUrl, $sellerId);
                    $result = $client->testConnection();

                    if ($result['ok']) {
                        Notification::make()->title(__('settings.payme_ok'))->success()->send();
                    } else {
                        Notification::make()->title(__('settings.payme_failed'))->body($result['detail'] ?: null)->danger()->persistent()->send();
                    }
                }),
            Action::make('testEmail')
                ->label(__('settings.test_email'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->action(function (): void {
                    try {
                        $to = auth()->user()?->email;
                        Mail::raw(__('settings.test_email_body'), function ($m) use ($to) {
                            $m->to($to)->subject(__('settings.test_email_subject'));
                            $from = MailSetting::current();
                            if ($from->from_address) {
                                $m->from($from->from_address, $from->from_name ?: 'Mills');
                            }
                        });
                        Notification::make()->title(__('settings.test_email_sent', ['email' => $to]))->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title(__('settings.test_email_failed'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
