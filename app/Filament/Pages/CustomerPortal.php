<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\SystemLog;
use App\Support\PhoneNumber;
use App\Support\StorefrontToken;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Open a customer's personal area from a phone number.
 *
 * When a customer calls and says "it doesn't work", the fastest answer is to look at exactly
 * what they are looking at. Finding their subscription first, then opening the portal from
 * there, is three screens away; a phone number is what the person on the phone actually has.
 *
 * The link is READ-ONLY on purpose — StorefrontToken::mintPreview, 30 minutes, GET only,
 * enforced in VerifyStorefrontToken. Support has no business writing as the customer, and a
 * tool that can silently change someone's subscription is a liability rather than a feature.
 *
 * Every use is logged. Opening someone's personal area is a privacy-relevant act, and "who
 * looked at this customer's account" should always have an answer.
 */
class CustomerPortal extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTopRightOnSquare;

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.customer-portal';

    /** @var array<string, mixed> */
    public array $data = [];

    /** Set once a lookup succeeds — the view renders the link and the customer's name. */
    public ?string $portalUrl = null;

    public ?string $foundName = null;

    public static function getNavigationLabel(): string
    {
        return __('portal.title');
    }

    public function getTitle(): string
    {
        return __('portal.title');
    }

    public function mount(): void
    {
        $this->form->fill(['phone' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->statePath('data')
            ->components([
                Section::make(__('portal.lookup_title'))
                    ->description(__('portal.lookup_help'))
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('portal.phone'))
                            ->tel()
                            ->required()
                            ->placeholder('050-0000000')
                            ->helperText(__('portal.phone_help'))
                            ->autofocus(),
                    ]),
            ]);
    }

    public function open(): void
    {
        $this->portalUrl = null;
        $this->foundName = null;

        $data = $this->form->getState();
        $phone = trim((string) ($data['phone'] ?? ''));

        // Normalised, never matched as a raw string: 050-123-4567, 0501234567 and
        // +972501234567 are one customer, and an exact match would find none of them.
        $customer = Customer::findByPhone($phone);

        if ($customer === null) {
            Notification::make()
                ->title(__('portal.not_found'))
                ->body(__('portal.not_found_help', ['phone' => PhoneNumber::local($phone) ?? $phone]))
                ->warning()
                ->send();

            return;
        }

        $shopifyId = (string) ($customer->shopify_customer_id ?? '');

        if ($shopifyId === '') {
            // The token's subject IS the Shopify id (frozen v1 format), so a customer who was
            // never linked to Shopify cannot have one minted at all.
            Notification::make()
                ->title(__('portal.no_shopify_id'))
                ->body(__('portal.no_shopify_id_help'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $base = rtrim((string) config('shopify.storefront_url', ''), '/');

        if ($base === '') {
            Notification::make()->title(__('portal.no_base_url'))->danger()->send();

            return;
        }

        $this->portalUrl = $base.'?mills_preview='.urlencode(StorefrontToken::mintPreview($shopifyId));
        $this->foundName = $customer->fullName();

        SystemLog::info('admin', "a customer's personal area was opened by phone lookup (read-only)", [
            'admin_id' => auth()->id(),
        ], ['customer_id' => $customer->id]);
    }
}
