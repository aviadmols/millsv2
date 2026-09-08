<?php

namespace App\Filament\Resources\PaymentLedgers\Tables;

use App\Domain\Billing\IdempotencyKey;
use App\Models\PaymentLedger;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use App\Modules\MillsSubscriptions\Services\RefundService;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class PaymentLedgersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subscription.id')->label(__('ledgers.subscription'))
                    ->searchable(),
                TextColumn::make('customer.id')->label(__('ledgers.customer'))
                    ->searchable(),
                TextColumn::make('paymentMethod.id')->label(__('ledgers.payment_method'))
                    ->searchable(),
                // Both of these are enum values. Rendered raw they read "card_update" and
                // "retry_scheduled" — English, in the middle of a Hebrew table.
                TextColumn::make('context')->label(__('ledgers.context'))
                    ->formatStateUsing(fn ($state) => self::translated('subscriptions.ctx_', $state))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('idempotency_key')->label(__('ledgers.idempotency_key'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('ledgers.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::translated('subscriptions.ledger_', $state))
                    ->color(fn ($state) => match ((string) ($state->value ?? $state)) {
                        'succeeded' => 'success',
                        'pending', 'retry_scheduled' => 'warning',
                        'refunded' => 'gray',
                        default => 'danger',
                    })
                    ->searchable(),
                TextColumn::make('amount')->label(__('ledgers.amount'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('currency')->label(__('ledgers.currency'))
                    ->searchable(),
                TextColumn::make('payme_transaction_id')->label(__('ledgers.payme_transaction_id'))
                    ->searchable(),
                TextColumn::make('shopify_order_id')->label(__('ledgers.shopify_order_id'))
                    ->searchable(),
                TextColumn::make('draft_order_id')->label(__('ledgers.draft_order_id'))
                    ->searchable(),
                TextColumn::make('failure_code')->label(__('ledgers.failure_code'))
                    ->searchable(),
                TextColumn::make('executed_at')->label(__('ledgers.executed_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')->label(__('ledgers.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('ledgers.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                self::refund(),
                self::retryCardUpdate(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** An enum value as its Hebrew label, degrading to the raw value rather than to a key. */
    private static function translated(string $prefix, mixed $state): string
    {
        $value = (string) ($state->value ?? $state);
        $key = $prefix.$value;

        return __($key) === $key ? $value : __($key);
    }

    /**
     * Rescue a card update whose confirmation failed AFTER the customer entered the card.
     *
     * The pending card_update row means PayMe may hold a captured card we never received
     * (get-buyer-key refused — 21 Aug: "Merchant not allowed to use this buyer"). consume()
     * resolves the session from this very row, so asking again costs the customer nothing:
     * no new charge, no new SMS. On success the wall lifts exactly as if the callback had
     * worked; on refusal the row stays pending for the reconciler.
     */
    /**
     * Give the money back — through PayMe, where it actually is.
     *
     * Refunding in Shopify does nothing to the card: the charge never went through Shopify,
     * and its refund on such an order is bookkeeping. An admin did exactly that on order
     * 19019226579248 and the customer received nothing. This button is the real thing: PayMe
     * returns the money, the ledger records it, and the Shopify order is marked to match.
     *
     * Offered only on a charge that succeeded and still has something left to return.
     */
    private static function refund(): Action
    {
        return Action::make('refund')
            ->label(__('ledgers.refund'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (PaymentLedger $record): bool => $record->status === LedgerStatus::SUCCEEDED
                && in_array($record->context, IdempotencyKey::billingContexts(), true)
                && filled($record->payme_transaction_id))
            ->modalHeading(__('ledgers.refund_heading'))
            ->modalDescription(fn (PaymentLedger $record) => __('ledgers.refund_help', [
                'amount' => '₪'.number_format(self::remaining($record), 2),
            ]))
            ->modalSubmitActionLabel(__('ledgers.refund_submit'))
            ->schema([
                TextInput::make('amount')
                    ->label(__('ledgers.refund_amount'))
                    ->helperText(__('ledgers.refund_amount_help'))
                    ->numeric()
                    ->prefix('₪')
                    ->minValue(0.01)
                    ->maxValue(fn (PaymentLedger $record) => self::remaining($record))
                    ->default(fn (PaymentLedger $record) => self::remaining($record))
                    ->required(),

                Textarea::make('reason')
                    ->label(__('ledgers.refund_reason'))
                    ->helperText(__('ledgers.refund_reason_help'))
                    ->rows(2)
                    ->maxLength(200),
            ])
            ->action(function (PaymentLedger $record, array $data): void {
                try {
                    $result = app(RefundService::class)->refund(
                        $record,
                        (float) $data['amount'],
                        Timeline::admin((int) auth()->id()),
                        trim((string) ($data['reason'] ?? '')),
                    );

                    Notification::make()
                        ->title(__($result['full'] ? 'ledgers.refund_done' : 'ledgers.refund_done_part', [
                            'amount' => '₪'.number_format($result['refunded'], 2),
                        ]))
                        ->success()
                        ->persistent()
                        ->send();
                } catch (RuntimeException $e) {
                    // The code is a translation key; anything else is PayMe's own words.
                    $key = 'ledgers.'.$e->getMessage();

                    Notification::make()
                        ->title(__('ledgers.refund_failed'))
                        ->body(__($key) === $key ? $e->getMessage() : __($key))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    /** What has not gone back yet: the charge less anything already refunded. */
    private static function remaining(PaymentLedger $record): float
    {
        return max(0.0, round((float) $record->amount - (float) ($record->refunded_amount ?? 0), 2));
    }

    private static function retryCardUpdate(): Action
    {
        return Action::make('retryCardUpdate')
            ->label(__('subscriptions.ledger_retry_card_update'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (PaymentLedger $record): bool => $record->context === IdempotencyKey::CONTEXT_CARD_UPDATE
                && $record->status === LedgerStatus::PENDING
                && filled($record->meta['session_id'] ?? null))
            ->requiresConfirmation()
            ->modalDescription(__('subscriptions.ledger_retry_card_update_help'))
            ->action(function (PaymentLedger $record): void {
                try {
                    $result = app(CardUpdateService::class)->consume((string) $record->meta['session_id']);

                    Notification::make()
                        ->title(__('subscriptions.ledger_retry_recovered', ['count' => (int) ($result['subscriptions_unblocked'] ?? 0)]))
                        ->success()
                        ->send();
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title(__('subscriptions.ledger_retry_failed', ['reason' => $e->getMessage()]))
                        ->danger()
                        ->send();
                }
            });
    }
}
