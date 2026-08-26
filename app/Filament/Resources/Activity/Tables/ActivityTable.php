<?php

namespace App\Filament\Resources\Activity\Tables;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\Ui\EventPresenter;
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
            Timeline::KIND_ADMIN_NOTE,
            Timeline::KIND_NOTE,
        ];
    }

    private static function label(string $kind): string
    {
        return EventPresenter::labelFor($kind);
    }

    /** "admin:7" reads as a person; "system" and "customer" read as themselves. */
    private static function actorLabel(string $actor): string
    {
        return EventPresenter::actorLabel($actor);
    }

    /**
     * The one-line story of the event.
     *
     * Shared with the timeline on the subscription screen (EventPresenter): the same event
     * must not read one way in the feed and another way on the subscription it belongs to.
     * A note carries no summary — its prose IS the content, so it is shown instead.
     */
    private static function summarise(ActivityEvent $record): string
    {
        $summary = EventPresenter::summarize($record);

        return $summary === '' ? (EventPresenter::note($record) ?? '—') : $summary;
    }
}
