<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\Ui\EventPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who left, and when.
 *
 * The home tab says "4 churned" and stops there, which is the least useful form the number
 * can take: a count nobody can act on. The names, the dates and how long each customer
 * lasted are what turn churn from a statistic into something you can ask questions about.
 *
 * Read from the ACTIVITY LOG, not from the subscriptions table. A subscription cancelled
 * today and one cancelled a year ago are identical in `status` — the date a customer left
 * exists only as the event that recorded the change, which is also the only place that
 * knows whether they cancelled themselves or somebody did it for them.
 */
class Churn extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    /** Directly under the home tab: this is the other half of the churn number shown there. */
    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.churn';

    public static function getNavigationLabel(): string
    {
        return __('churn.title');
    }

    public function getHeading(): string
    {
        return __('churn.title');
    }

    public function getSubheading(): ?string
    {
        return __('churn.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => self::query())
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('churn.empty'))
            ->emptyStateDescription(__('churn.empty_help'))
            ->filters([
                /*
                 * The same windows as the home tab, so a number seen there can be opened
                 * here and add up. Calendar days from midnight — "today" means today.
                 */
                Filter::make('period')
                    ->schema([
                        Select::make('days')
                            ->label(__('churn.period'))
                            ->options(Dashboard::periods())
                            ->placeholder(__('churn.period_all')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['days'] ?? null,
                        fn (Builder $q, $days) => $q->where(
                            'activity_events.created_at',
                            '>=',
                            now()->subDays(max(1, (int) $days) - 1)->startOfDay(),
                        ),
                    ))
                    ->indicateUsing(fn (array $data) => ($data['days'] ?? null)
                        ? __('churn.period').': '.(Dashboard::periods()[(int) $data['days']] ?? $data['days'])
                        : null),

                // A specific day, for "what happened on the 14th" — the question a spike
                // on any chart immediately raises.
                Filter::make('day')
                    ->schema([
                        DatePicker::make('date')->label(__('churn.day'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'] ?? null,
                        fn (Builder $q, $date) => $q->whereDate('activity_events.created_at', $date),
                    ))
                    ->indicateUsing(fn (array $data) => ($data['date'] ?? null)
                        ? __('churn.day').': '.$data['date']
                        : null),
            ])
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('churn.left_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (ActivityEvent $record) => $record->created_at?->diffForHumans()),

                TextColumn::make('customer.email')
                    ->label(__('subscriptions.customer'))
                    ->searchable()
                    ->description(fn (ActivityEvent $record) => $record->customer?->fullName()),

                // How long they stayed. The single most useful thing about a churn list:
                // someone who left after two cycles is a different problem from someone
                // who left after two years, and the count on the home tab cannot tell you.
                TextColumn::make('tenure')
                    ->label(__('churn.tenure'))
                    ->state(fn (ActivityEvent $record) => self::tenure($record))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('actor')
                    ->label(__('churn.who'))
                    ->state(fn (ActivityEvent $record) => EventPresenter::actorLabel($record))
                    ->badge()
                    ->color(fn (ActivityEvent $record) => $record->actor === Timeline::ACTOR_CUSTOMER ? 'warning' : 'gray'),

                TextColumn::make('from')
                    ->label(__('churn.from_status'))
                    ->state(fn (ActivityEvent $record) => self::fromStatus($record))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('dashboard.open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (ActivityEvent $record) => $record->subscription_id
                        ? SubscriptionResource::getUrl('view', ['record' => $record->subscription_id])
                        : null)
                    ->hidden(fn (ActivityEvent $record) => $record->subscription_id === null),
            ]);
    }

    /**
     * Every recorded cancellation, newest first.
     *
     * Keyed on the event rather than the subscription so a date exists at all — and so a
     * subscription that was cancelled, reinstated by an admin and cancelled again tells
     * the truth about both departures instead of collapsing into one row.
     */
    private static function query(): Builder
    {
        return ActivityEvent::query()
            ->with(['customer', 'subscription'])
            ->where('kind', Timeline::KIND_STATUS_CHANGED)
            ->where('details->to', SubscriptionStatus::CANCELLED->value);
    }

    private static function tenure(ActivityEvent $event): string
    {
        $started = $event->subscription?->created_at;

        if ($started === null || $event->created_at === null) {
            return '—';
        }

        $days = (int) $started->startOfDay()->diffInDays($event->created_at->startOfDay());

        return $days < 31
            ? __('churn.tenure_days', ['days' => $days])
            : __('churn.tenure_months', ['months' => (int) floor($days / 30)]);
    }

    private static function fromStatus(ActivityEvent $event): string
    {
        $from = (string) (((array) ($event->details ?? []))['from'] ?? '');
        $key = 'subscriptions.status_'.$from;

        return $from === '' ? '—' : (__($key) === $key ? $from : __($key));
    }
}
