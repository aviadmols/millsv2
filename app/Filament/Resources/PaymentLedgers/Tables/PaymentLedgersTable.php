<?php

namespace App\Filament\Resources\PaymentLedgers\Tables;

use App\Domain\Billing\IdempotencyKey;
use App\Models\PaymentLedger;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
