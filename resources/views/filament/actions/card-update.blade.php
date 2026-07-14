{{--
    The card-update modal.

    Card data NEVER touches this page — it is entered on PayMe's own hosted page, inside the
    iframe. What we hold is a session id and a URL.

    The link is shown beside the iframe on purpose, not as a fallback nobody finds: if PayMe
    ever serves X-Frame-Options: DENY the iframe goes blank, and a flow whose only path is an
    iframe on somebody else's domain is a flow that breaks silently. The same link is what the
    customer receives by SMS.
--}}
@php
    $hostedUrl = $session['hosted_url'] ?? null;
@endphp

@if ($hostedUrl === null)
    <x-filament::section>
        <p class="fi-color-danger">{{ __('subscriptions.card_update_failed_body') }}</p>
    </x-filament::section>
@else
    <div
        class="fi-sc-has-gap"
        x-data="{
            listen() {
                window.addEventListener('message', (event) => {
                    {{-- Same-origin ONLY. The callback page is ours; accepting '*' would let any
                         page we frame tell the admin that a card was saved when none was. --}}
                    if (event.origin !== window.location.origin) return;
                    if (event.data?.type !== 'mills:card-update') return;

                    $wire.call('cardUpdated', event.data.ok === true);
                });
            },
        }"
        x-init="listen()"
    >
        <iframe
            src="{{ $hostedUrl }}"
            title="{{ __('subscriptions.action_update_card') }}"
            allow="payment"
            width="100%"
            height="560"
            style="border: 0; border-radius: 0.5rem;"
        ></iframe>

        <x-filament::section class="fi-mt-4">
            <x-slot name="heading">{{ __('subscriptions.card_update_link') }}</x-slot>
            <x-slot name="description">{{ __('subscriptions.card_update_link_help') }}</x-slot>

            <div class="fi-sc-has-gap">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" :value="$hostedUrl" readonly />
                </x-filament::input.wrapper>

                <div class="fi-ac">
                    <x-filament::button
                        tag="a"
                        href="{{ $hostedUrl }}"
                        target="_blank"
                        color="gray"
                        icon="heroicon-o-arrow-top-right-on-square"
                    >
                        {{ __('subscriptions.card_update_open_tab') }}
                    </x-filament::button>

                    @if (filled($record->customer?->phone))
                        <x-filament::button
                            wire:click="sendCardUpdateSms"
                            wire:loading.attr="disabled"
                            color="primary"
                            icon="heroicon-o-chat-bubble-left-right"
                        >
                            {{ __('subscriptions.card_update_send_sms') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>
    </div>
@endif
