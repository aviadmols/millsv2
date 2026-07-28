<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use App\Modules\MillsSubscriptions\Support\SmsTemplate;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Edit the text of the SMS messages customers receive.
 *
 * The wording lived in lang files, so changing it meant a code change and a deploy — for copy
 * that is a marketing decision, not an engineering one.
 *
 * The guard that matters is the placeholder check. An OTP message saved without `:code` is a
 * text message that tells the customer nothing, and every login breaks silently until someone
 * complains. The form refuses to save one, and SmsTemplate falls back to the shipped default
 * if a bad value ever reaches the database another way.
 */
class SmsMessages extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 85;

    protected string $view = 'filament.pages.sms-messages';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('sms_messages.title');
    }

    public function getTitle(): string
    {
        return __('sms_messages.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'otp_body' => SmsTemplate::body('otp.sms.body'),
            'card_update_body' => SmsTemplate::body('subscriptions.sms_card_update'),
            'test_phone' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->statePath('data')
            ->components([
                Section::make(__('sms_messages.otp_title'))
                    ->description(__('sms_messages.otp_help'))
                    ->schema([
                        Textarea::make('otp_body')
                            ->hiddenLabel()
                            ->rows(3)
                            ->required()
                            ->maxLength(480)
                            ->live(debounce: 400)
                            // Without :code the customer receives a message with no code in it.
                            ->rule(fn () => function (string $attribute, mixed $value, callable $fail) {
                                if (! str_contains((string) $value, ':code')) {
                                    $fail(__('sms_messages.missing_placeholder', ['placeholder' => ':code']));
                                }
                            })
                            ->helperText(__('sms_messages.otp_placeholders')),

                        TextInput::make('otp_preview')
                            ->label(__('sms_messages.preview'))
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(
                                str_replace(':code', '123456', (string) $get('otp_body'))
                            ))
                            ->formatStateUsing(fn (Get $get) => str_replace(':code', '123456', (string) $get('otp_body'))),
                    ]),

                Section::make(__('sms_messages.card_title'))
                    ->description(__('sms_messages.card_help'))
                    ->schema([
                        Textarea::make('card_update_body')
                            ->hiddenLabel()
                            ->rows(3)
                            ->required()
                            ->maxLength(480)
                            ->live(debounce: 400)
                            // Without :url the customer gets a message asking them to update a
                            // card, with no way to do it.
                            ->rule(fn () => function (string $attribute, mixed $value, callable $fail) {
                                if (! str_contains((string) $value, ':url')) {
                                    $fail(__('sms_messages.missing_placeholder', ['placeholder' => ':url']));
                                }
                            })
                            ->helperText(__('sms_messages.card_placeholders')),

                        TextInput::make('card_preview')
                            ->label(__('sms_messages.preview'))
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn (Get $get) => str_replace(
                                ':url',
                                'https://mills.lets.co.il/…',
                                (string) $get('card_update_body')
                            )),
                    ]),

                Section::make(__('sms_messages.test_title'))
                    ->description(__('sms_messages.test_help'))
                    ->schema([
                        TextInput::make('test_phone')
                            ->label(__('sms_messages.test_phone'))
                            ->tel()
                            ->placeholder('050-0000000'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('sms_messages.save'))
                ->icon(Heroicon::OutlinedCheck)
                ->action('save'),

            Action::make('sendTest')
                ->label(__('sms_messages.send_test'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action('sendTest'),

            Action::make('reset')
                ->label(__('sms_messages.reset'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('sms_messages.reset_confirm'))
                ->action('resetToDefaults'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();   // validation runs here; a missing placeholder stops it

        AppSetting::put(SmsTemplate::settingKey('otp.sms.body'), trim((string) $data['otp_body']));
        AppSetting::put(SmsTemplate::settingKey('subscriptions.sms_card_update'), trim((string) $data['card_update_body']));

        SystemLog::info('admin', 'the SMS message texts were changed', [
            'admin_id' => auth()->id(),
        ]);

        Notification::make()->title(__('sms_messages.saved'))->success()->send();
    }

    /** Send the CURRENT text to a phone, so the wording is proved before customers get it. */
    public function sendTest(): void
    {
        $data = $this->form->getState();
        $phone = trim((string) ($data['test_phone'] ?? ''));

        if ($phone === '') {
            Notification::make()->title(__('sms_messages.test_no_phone'))->warning()->send();

            return;
        }

        $message = str_replace(':code', '123456', (string) $data['otp_body']);

        if (app(SmsSender::class)->send($phone, $message)) {
            Notification::make()->title(__('sms_messages.test_sent'))->success()->send();

            return;
        }

        Notification::make()
            ->title(__('sms_messages.test_failed'))
            ->body(__('sms_messages.test_failed_help'))
            ->danger()
            ->persistent()
            ->send();
    }

    /** Drop the overrides; SmsTemplate then falls back to the shipped wording. */
    public function resetToDefaults(): void
    {
        foreach (array_keys(SmsTemplate::TEMPLATES) as $key) {
            AppSetting::put(SmsTemplate::settingKey($key), '');
        }

        $this->mount();

        Notification::make()->title(__('sms_messages.reset_done'))->success()->send();
    }
}
