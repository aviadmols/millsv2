<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\PaymentLedger;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Customers who paid and have no order — so nothing will be shipped to them.
 *
 * Creating the Shopify order after a charge is deliberately compensating: the money has
 * moved, so a failure there never unwinds the charge. That is the right call for the money
 * and it made the failure silent. Subscription 321 was charged ₪414 at midnight on 11
 * September, Shopify refused the order, and the only trace was a row in the system log —
 * found four days later, by someone looking for something else.
 *
 * So it sits at the top of the home screen, and only when there is something in it: every
 * row is a person who has paid and is waiting for a box that is not coming. Once an admin
 * has dealt with one, "resolve" takes it off — otherwise handled cases pile up and bury the
 * next real one.
 */
class MissingOrders extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Top of the page, with the other "a real customer is affected right now" alerts. */
    protected static ?int $sort = 0;

    protected string $view = 'filament.widgets.missing-orders';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // An empty alert is silence, not an empty box.
        return PaymentLedger::query()->missingOrder()->exists();
    }

    /** @return Collection<int, PaymentLedger> */
    public function getMissing(): Collection
    {
        return PaymentLedger::query()
            ->missingOrder()
            ->with(['subscription.customer'])
            ->latest('executed_at')
            ->limit(50)
            ->get();
    }

    public function subscriptionUrl(PaymentLedger $ledger): ?string
    {
        return $ledger->subscription_id
            ? SubscriptionResource::getUrl('view', ['record' => $ledger->subscription_id])
            : null;
    }

    /*
     * Not "resolveAction": InteractsWithActions already has a protected resolveAction() it
     * uses to mount every action, and a method here by that name silently replaces it.
     */
    public function markResolvedAction(): Action
    {
        return Action::make('markResolved')
            ->label(__('dashboard.missing_orders_resolve'))
            ->icon('heroicon-o-check')
            ->color('gray')
            ->size('sm')
            ->modalHeading(__('dashboard.missing_orders_resolve_heading'))
            ->modalDescription(__('dashboard.missing_orders_resolve_help'))
            ->modalSubmitActionLabel(__('dashboard.missing_orders_resolve_submit'))
            ->modalWidth('lg')
            ->schema([
                TextInput::make('order')
                    ->label(__('dashboard.missing_orders_resolve_order'))
                    ->helperText(__('dashboard.missing_orders_resolve_order_help'))
                    ->placeholder('https://admin.shopify.com/store/millsforpets/orders/…')
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && PaymentLedger::shopifyOrderIdFrom((string) $value) === null) {
                            $fail(__('dashboard.missing_orders_resolve_order_invalid'));
                        }
                    }),

                Textarea::make('note')
                    ->label(__('dashboard.missing_orders_resolve_note'))
                    ->placeholder(__('dashboard.missing_orders_resolve_note_placeholder'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $arguments, array $data): void {
                // Only a row that is still in the alert — a second click, or a row the order
                // arrived for in the meantime, changes nothing.
                $ledger = PaymentLedger::query()->missingOrder()->find((int) ($arguments['ledger'] ?? 0));

                if ($ledger === null) {
                    return;
                }

                $orderId = PaymentLedger::shopifyOrderIdFrom($data['order'] ?? null);
                $note = trim((string) ($data['note'] ?? ''));
                $actor = Timeline::admin((int) auth()->id());

                $ledger->resolveMissingOrder($actor, $orderId, $note);

                // On the subscription's own history too, where support reads it later.
                Timeline::record(Timeline::KIND_ADMIN_NOTE, [
                    'note' => __('dashboard.missing_orders_resolved_timeline', [
                        'amount' => '₪'.number_format((float) $ledger->amount, 2),
                        'date' => $ledger->executed_at?->format('d.m.Y') ?? '—',
                    ]).($orderId ? ' · '.__('dashboard.missing_orders_resolved_linked', ['id' => $orderId]) : '')
                        .($note !== '' ? ' — '.$note : ''),
                ], $ledger->subscription_id, $ledger->customer_id, $actor);

                Notification::make()
                    ->title(__('dashboard.missing_orders_resolved'))
                    ->success()
                    ->send();
            });
    }
}
