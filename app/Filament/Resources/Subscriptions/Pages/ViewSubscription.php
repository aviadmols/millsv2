<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\SubscriptionActions;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
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
            $this->pauseAction(),
            $this->resumeAction(),
            $this->postponeAction(),
            $this->chargeNowAction(),
            $this->buildDraftAction(),
            EditAction::make(),
        ];
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
