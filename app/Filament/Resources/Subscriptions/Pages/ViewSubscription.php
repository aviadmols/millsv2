<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Services\SubscriptionActions;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use App\Support\ShopifyId;
use App\Support\StorefrontToken;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The subscription screen and everything an admin can do from it.
 *
 * Two of these actions move money or stop it, so each says plainly what it is about to do
 * before it does it. "Charge now" is a REAL charge and cannot be undone from here, so it
 * confirms with the exact amount — and refuses outright when that amount is unknown,
 * because the one thing this system may not do with money is guess.
 */
class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->customerPortalAction(),
            $this->editUpcomingOrderAction(),
            $this->pauseAction(),
            $this->resumeAction(),
            $this->postponeAction(),
            $this->chargeNowAction(),
            $this->buildDraftAction(),
            EditAction::make(),
        ];
    }

    /**
     * Open the customer's personal area, exactly as they see it.
     *
     * The token is a READ-ONLY preview (30 minutes, GET-only, enforced in
     * VerifyStorefrontToken). Support staff have no business writing as the customer, and a
     * tool that can silently change someone's subscription is a liability, not a feature.
     */
    private function customerPortalAction(): Action
    {
        return Action::make('customerPortal')
            ->label(__('subscriptions.action_customer_portal'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (Subscription $record) => ! empty($record->customer?->shopify_customer_id)
                && config('shopify.storefront_url') !== null)
            ->url(fn (Subscription $record) => $this->portalUrl($record))
            ->openUrlInNewTab();
    }

    private function portalUrl(Subscription $record): ?string
    {
        $base = (string) config('shopify.storefront_url', '');
        $shopifyId = (string) ($record->customer?->shopify_customer_id ?? '');

        if ($base === '' || $shopifyId === '') {
            return null;
        }

        SystemLog::info('admin', 'customer portal opened by an admin (read-only)', [
            'admin_id' => auth()->id(),
        ], ['subscription_id' => $record->id, 'customer_id' => $record->customer_id]);

        return rtrim($base, '/').'?mills_preview='.urlencode(StorefrontToken::mintPreview($shopifyId));
    }

    /**
     * Change what goes out next: quantities, extra products, removals.
     *
     * The edit is stored on the SUBSCRIPTION, not just pushed at the Shopify draft — the
     * draft is a projection, so editing only it would leave the charge still billing the
     * original lines, and the customer would be charged for one thing and shipped another.
     *
     * It is a one-off: after the cycle is charged, the order goes back to the dogs' real
     * products. A permanent change belongs on the dog.
     */
    private function editUpcomingOrderAction(): Action
    {
        return Action::make('editUpcomingOrder')
            ->label(__('subscriptions.action_edit_upcoming'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->modalHeading(__('subscriptions.action_edit_upcoming'))
            ->modalDescription(__('subscriptions.action_edit_upcoming_help'))
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(fn (Subscription $record) => $record->status !== SubscriptionStatus::CANCELLED)
            ->fillForm(fn (Subscription $record) => ['lines' => $this->currentLines($record)])
            ->schema([
                Repeater::make('lines')
                    ->label(__('subscriptions.products'))
                    ->addActionLabel(__('subscriptions.add_product'))
                    ->reorderable(false)
                    ->columns(3)
                    ->schema([
                        Select::make('variant_id')
                            ->label(__('subscriptions.product'))
                            ->options(fn () => self::variantOptions())
                            ->searchable()
                            ->required()
                            ->columnSpan(2),
                        TextInput::make('quantity')
                            ->label(__('subscriptions.quantity'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ]),
            ])
            ->action(function (Subscription $record, array $data) {
                $lines = collect($data['lines'] ?? [])
                    ->filter(fn ($line) => ! empty($line['variant_id']) && (int) ($line['quantity'] ?? 0) >= 1)
                    ->map(fn ($line) => [
                        'variant_id' => (string) $line['variant_id'],
                        'quantity' => (int) $line['quantity'],
                    ])
                    ->values()
                    ->all();

                if ($lines === []) {
                    Notification::make()
                        ->title(__('subscriptions.upcoming_needs_a_line'))
                        ->warning()
                        ->send();

                    return;
                }

                $record->forceFill([
                    'line_items_override' => $lines,
                    'line_items_overridden_at' => now(),
                ])->save();

                try {
                    // Rebuild the draft so the screen, the amount and the shipment agree.
                    $draft = $this->actions()->refreshUpcomingOrder($record->fresh());
                } catch (Throwable $e) {
                    $this->fail(__('subscriptions.draft_failed'), $e->getMessage());

                    return;
                }

                SystemLog::warning('admin', 'the upcoming order was edited by hand', [
                    'admin_id' => auth()->id(),
                    'lines' => $lines,
                    'new_total' => $draft['total'] ?? null,
                ], ['subscription_id' => $record->id, 'customer_id' => $record->customer_id]);

                Notification::make()
                    ->title(__('subscriptions.upcoming_updated', [
                        'total' => '₪'.number_format((float) ($draft['total'] ?? 0), 2),
                    ]))
                    ->success()
                    ->send();

                $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
            });
    }

    /**
     * What the next order currently contains — the override if one was made, otherwise the
     * lines derived from the dogs.
     *
     * @return list<array{variant_id: string, quantity: int}>
     */
    private function currentLines(Subscription $subscription): array
    {
        return collect(app(DraftOrderService::class)->lineItems($subscription))
            ->map(fn (array $line) => [
                'variant_id' => ShopifyId::numeric((string) $line['variantId']),
                'quantity' => (int) $line['quantity'],
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function variantOptions(): array
    {
        return ProductVariant::query()
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('position')
            ->get()
            ->mapWithKeys(fn (ProductVariant $v) => [(string) $v->shopify_variant_id => VariantResolver::label($v)])
            ->all();
    }

    private function actions(): SubscriptionActions
    {
        return app(SubscriptionActions::class);
    }

    /** Stop billing. The dispatcher only selects ACTIVE rows, so this genuinely stops it. */
    private function pauseAction(): Action
    {
        return Action::make('pause')
            ->label(__('subscriptions.action_pause'))
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('subscriptions.action_pause'))
            ->modalDescription(__('subscriptions.action_pause_confirm'))
            ->visible(fn (Subscription $record) => $record->status === SubscriptionStatus::ACTIVE)
            ->action(function (Subscription $record) {
                try {
                    $this->actions()->pause($record);
                } catch (Throwable $e) {
                    $this->fail(__('subscriptions.action_failed'), $e->getMessage());

                    return;
                }

                Notification::make()->title(__('subscriptions.paused_ok'))->success()->send();
                $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
            });
    }

    private function resumeAction(): Action
    {
        return Action::make('resume')
            ->label(__('subscriptions.action_resume'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('subscriptions.action_resume'))
            ->modalDescription(__('subscriptions.action_resume_confirm'))
            ->visible(fn (Subscription $record) => in_array($record->status, [
                SubscriptionStatus::PAUSED,
                SubscriptionStatus::PAST_DUE,
            ], true))
            ->action(function (Subscription $record) {
                try {
                    $this->actions()->resume($record);
                } catch (Throwable $e) {
                    $this->fail(__('subscriptions.action_failed'), $e->getMessage());

                    return;
                }

                Notification::make()->title(__('subscriptions.resumed_ok'))->success()->send();
                $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
            });
    }

    /** Skip this cycle: push the next charge forward (default: one full period). */
    private function postponeAction(): Action
    {
        return Action::make('postpone')
            ->label(__('subscriptions.action_postpone'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->modalHeading(__('subscriptions.action_postpone'))
            ->modalDescription(__('subscriptions.action_postpone_confirm'))
            ->visible(fn (Subscription $record) => $record->status !== SubscriptionStatus::CANCELLED)
            ->schema(fn (Subscription $record) => [
                DatePicker::make('until')
                    ->label(__('subscriptions.postpone_until'))
                    ->helperText(__('subscriptions.postpone_help'))
                    ->native(false)
                    ->minDate(now()->addDay())
                    ->default(
                        ($record->next_charge_at ?? now())
                            ->copy()
                            ->addMonthsNoOverflow(max(1, (int) $record->frequency_months)),
                    ),
            ])
            ->action(function (Subscription $record, array $data) {
                try {
                    $until = $this->actions()->postponeNextCharge(
                        $record,
                        ! empty($data['until']) ? Carbon::parse($data['until']) : null,
                    );
                } catch (Throwable $e) {
                    $this->fail(__('subscriptions.action_failed'), $e->getMessage());

                    return;
                }

                Notification::make()
                    ->title(__('subscriptions.postponed_ok', ['date' => $until->toDateString()]))
                    ->success()
                    ->send();

                $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
            });
    }

    /** Charge this cycle now. REAL MONEY. */
    private function chargeNowAction(): Action
    {
        return Action::make('chargeNow')
            ->label(__('subscriptions.action_charge_now'))
            ->icon(Heroicon::OutlinedBolt)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('subscriptions.action_charge_now'))
            ->modalDescription(fn (Subscription $record) => $record->next_charge_amount
                ? __('subscriptions.action_charge_now_confirm', [
                    'amount' => '₪'.number_format((float) $record->next_charge_amount, 2),
                ])
                : __('subscriptions.action_charge_now_no_amount'))
            ->modalSubmitActionLabel(__('subscriptions.action_charge_now_submit'))
            ->visible(fn (Subscription $record) => $record->status === SubscriptionStatus::ACTIVE)
            // No amount → no charge. The button is dead rather than guessing a number.
            ->disabled(fn (Subscription $record) => empty($record->next_charge_amount))
            ->action(function (Subscription $record) {
                $result = $this->actions()->chargeNow($record, (int) auth()->id());

                if (! ($result['success'] ?? false)) {
                    Notification::make()
                        ->title(__('subscriptions.charge_failed'))
                        ->body($this->chargeError($result))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->title(__('subscriptions.charged_ok'))->success()->send();
                $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
            });
    }

    /** Build (or rebuild) the upcoming order in Shopify — which also refreshes the amount. */
    private function buildDraftAction(): Action
    {
        return Action::make('buildDraft')
            ->label(__('subscriptions.action_build_draft'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('subscriptions.action_build_draft_confirm'))
            ->visible(fn (Subscription $record) => $record->status !== SubscriptionStatus::CANCELLED)
            ->action(function (Subscription $record) {
                try {
                    $draft = $this->actions()->refreshUpcomingOrder($record);
                } catch (Throwable $e) {
                    $this->fail(__('subscriptions.draft_failed'), $e->getMessage());

                    return;
                }

                if ($draft === []) {
                    Notification::make()
                        ->title(__('subscriptions.draft_no_products'))
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('subscriptions.draft_ok', [
                        'total' => '₪'.number_format((float) ($draft['total'] ?? 0), 2),
                    ]))
                    ->success()
                    ->send();

                $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
            });
    }

    /** @param array<string, mixed> $result */
    private function chargeError(array $result): string
    {
        $status = (string) ($result['status'] ?? 'failed');
        $key = 'subscriptions.charge_status_'.$status;

        return __($key) !== $key
            ? __($key)
            : (string) ($result['error'] ?? $status);
    }

    private function fail(string $title, string $message): void
    {
        Notification::make()->title($title)->body($message)->danger()->persistent()->send();
    }
}
