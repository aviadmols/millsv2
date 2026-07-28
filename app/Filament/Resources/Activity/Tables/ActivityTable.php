<?php

namespace App\Filament\Resources\Activity\Tables;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The activity feed, written for a person rather than for a debugger.
 *
 * Each row answers three things in the order a human asks them: WHAT happened, to WHOM, and
 * WHO did it. The raw `details` JSON is summarised into a sentence — "charged ₪153.90",
 * "postponed to 2026-08-14" — because a column of `{"ledger_id":7,"amount":"153.90"}` is a
 * log nobody reads twice.
 */
class ActivityTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('activity.when'))
                    ->dateTime('Y-m-d H:i')
                    ->description(fn (ActivityEvent $record) => $record->created_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('kind')
                    ->label(__('activity.event'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::label($state))
                    ->color(fn (string $state) => match ($state) {
                        Timeline::KIND_CHARGE_SUCCEEDED, Timeline::KIND_ORDER_CREATED => 'success',
                        Timeline::KIND_CHARGE_FAILED => 'danger',
                        Timeline::KIND_CARD_UPDATED, Timeline::KIND_STATUS_CHANGED => 'warning',
                        Timeline::KIND_QUIZ_LINKED, Timeline::KIND_SUBSCRIPTION_CREATED => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('summary')
                    ->label(__('activity.what'))
                    ->state(fn (ActivityEvent $record) => self::summarise($record))
                    ->wrap()
                    ->searchable(false),

                TextColumn::make('customer.email')
                    ->label(__('activity.customer'))
                    ->description(fn (ActivityEvent $record) => $record->customer?->fullName())
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('subscription_id')
                    ->label(__('activity.subscription'))
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : '#'.$state)
                    ->url(fn (ActivityEvent $record) => $record->subscription_id
                        ? SubscriptionResource::getUrl('view', ['record' => $record->subscription_id])
                        : null)
                    ->color(fn (ActivityEvent $record) => $record->subscription_id ? 'primary' : 'gray'),

                TextColumn::make('actor')
                    ->label(__('activity.who'))
                    ->formatStateUsing(fn (string $state) => self::actorLabel($state))
                    ->badge()
                    ->color('gray'),
            ])
            // Newest first — a feed is read from the top.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('activity.event'))
                    ->options(fn () => collect(self::kinds())
                        ->mapWithKeys(fn (string $k) => [$k => self::label($k)])
                        ->all())
                    ->multiple(),

                Filter::make('money')
                    ->label(__('activity.only_money'))
                    // The question "what happened to the money" deserves one click, not a
                    // manual scan of a mixed feed.
                    ->query(fn (Builder $query) => $query->whereIn('kind', [
                        Timeline::KIND_CHARGE_SUCCEEDED,
                        Timeline::KIND_CHARGE_FAILED,
                        Timeline::KIND_CARD_UPDATED,
                        Timeline::KIND_ORDER_CREATED,
                    ])),

                Filter::make('by_admin')
                    ->label(__('activity.only_admin'))
                    ->query(fn (Builder $query) => $query->where('actor', 'like', 'admin:%')),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading(__('activity.empty_heading'))
            ->emptyStateDescription(__('activity.empty_description'))
            ->poll('60s');
    }

    /** @return list<string> */
    private static function kinds(): array
    {
        return [
            Timeline::KIND_QUIZ_LINKED,
            Timeline::KIND_SUBSCRIPTION_CREATED,
            Timeline::KIND_CHARGE_SUCCEEDED,
            Timeline::KIND_CHARGE_FAILED,
            Timeline::KIND_ORDER_CREATED,
            Timeline::KIND_CARD_UPDATED,
            Timeline::KIND_STATUS_CHANGED,
            Timeline::KIND_ADDRESS_UPDATED,
            Timeline::KIND_DOG_UPDATED,
            Timeline::KIND_NOTE,
        ];
    }

    private static function label(string $kind): string
    {
        $key = 'activity.kind_'.$kind;

        return __($key) === $key ? str_replace('_', ' ', $kind) : __($key);
    }

    /** "admin:7" reads as a person; "system" and "customer" read as themselves. */
    private static function actorLabel(string $actor): string
    {
        if (str_starts_with($actor, 'admin:')) {
            return __('activity.actor_admin', ['id' => substr($actor, 6)]);
        }

        $key = 'activity.actor_'.$actor;

        return __($key) === $key ? $actor : __($key);
    }

    /**
     * The one-line story of the event.
     *
     * Built from `details`, whose shape differs per kind — so each kind gets the sentence it
     * deserves, and anything unrecognised falls back to a readable key: value list rather
     * than to raw JSON.
     */
    private static function summarise(ActivityEvent $record): string
    {
        $d = (array) ($record->details ?? []);

        return match ($record->kind) {
            Timeline::KIND_CHARGE_SUCCEEDED => __('activity.sum_charged', [
                'amount' => '₪'.number_format((float) ($d['amount'] ?? 0), 2),
            ]),
            Timeline::KIND_CHARGE_FAILED => __('activity.sum_charge_failed', [
                'reason' => (string) ($d['failure_code'] ?? $d['status'] ?? '—'),
            ]),
            Timeline::KIND_CARD_UPDATED => __('activity.sum_card_updated', [
                'count' => (int) ($d['subscriptions_unblocked'] ?? 0),
            ]).(($d['recovered_by_reconciliation'] ?? false) ? ' · '.__('activity.sum_recovered') : ''),
            Timeline::KIND_QUIZ_LINKED => __('activity.sum_quiz', [
                'dog' => (string) ($d['dog_name'] ?? '—'),
                'weight' => (string) ($d['weight'] ?? '?'),
            ]),
            Timeline::KIND_SUBSCRIPTION_CREATED => __('activity.sum_subscription_created', [
                'source' => (string) ($d['source'] ?? '—'),
                'dogs' => (int) ($d['dogs'] ?? 0),
            ]),
            Timeline::KIND_ORDER_CREATED => __('activity.sum_order', [
                'order' => (string) ($d['shopify_order_id'] ?? $d['order'] ?? '—'),
            ]),
            Timeline::KIND_STATUS_CHANGED => self::statusSummary($d),
            Timeline::KIND_ADDRESS_UPDATED => __('activity.sum_address'),
            default => self::readableDetails($d),
        };
    }

    /**
     * A status change, as written by transitionTo(): model, from, to, plus whatever context
     * the caller passed (a pause reason, for instance).
     *
     * @param  array<string, mixed>  $d
     */
    private static function statusSummary(array $d): string
    {
        if (isset($d['payment_state'])) {
            return __('activity.sum_payment_state', ['state' => (string) $d['payment_state']]);
        }

        $from = (string) ($d['from'] ?? '');
        $to = (string) ($d['to'] ?? '');

        if ($to === '') {
            return self::readableDetails($d);
        }

        $line = __('activity.sum_status', [
            'model' => self::modelLabel((string) ($d['model'] ?? '')),
            'from' => self::statusLabel($from),
            'to' => self::statusLabel($to),
        ]);

        $reason = trim((string) ($d['reason'] ?? ''));

        return $reason === '' ? $line : $line.' — '.$reason;
    }

    private static function statusLabel(string $status): string
    {
        $key = 'subscriptions.status_'.$status;

        return $status === '' ? '—' : (__($key) === $key ? $status : __($key));
    }

    private static function modelLabel(string $model): string
    {
        $key = 'activity.model_'.strtolower($model);

        return __($key) === $key ? $model : __($key);
    }

    /** @param array<string, mixed> $d */
    private static function readableDetails(array $d): string
    {
        if ($d === []) {
            return '—';
        }

        $parts = [];
        foreach ($d as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;   // nested payloads belong in the detail view, not a table cell
            }
            $parts[] = str_replace('_', ' ', (string) $key).': '.(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
