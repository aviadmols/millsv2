{{--
    The Cardcom hand-off queue. Every row is a customer being billed TWICE right now —
    by us (they saved a card) and by Cardcom (nobody removed the old charge yet).
--}}
<x-filament::section>
    <x-slot name="heading">{{ __('dashboard.cardcom_heading') }}</x-slot>
    <x-slot name="description">{{ __('dashboard.cardcom_description') }}</x-slot>

    <div class="mills-cardcom">
        @foreach ($this->getPending() as $customer)
            <div class="mills-cardcom__row" wire:key="cardcom-{{ $customer->id }}">
                <div class="mills-cardcom__who">
                    <div class="mills-cardcom__name">{{ $customer->fullName() }}</div>
                    <div class="mills-cardcom__meta">
                        @if ($customer->phone) <span dir="ltr">{{ $customer->phone }}</span> · @endif
                        {{ $customer->email }}
                    </div>
                </div>

                <div class="mills-cardcom__card">
                    @php $method = $customer->paymentMethods->first(); @endphp
                    <span class="mills-cardcom__masked" dir="ltr">{{ $method?->masked_card ?? '—' }}</span>
                    <span class="mills-cardcom__when">{{ $method?->captured_at?->format('d.m.Y H:i') }}</span>
                </div>

                <x-filament::button
                    color="success"
                    icon="heroicon-o-check"
                    wire:click="confirm({{ $customer->id }})"
                    wire:loading.attr="disabled"
                >
                    {{ __('dashboard.cardcom_confirm') }}
                </x-filament::button>
            </div>
        @endforeach
    </div>

    <style>
        .mills-cardcom { display: grid; gap: .5rem; }
        .mills-cardcom__row {
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
            padding: .625rem .75rem; border-radius: .5rem;
            border: 1px solid rgb(253 230 138 / 1); background: rgb(255 251 235 / .6);
        }
        .dark .mills-cardcom__row { border-color: rgb(120 90 20 / 1); background: rgb(60 50 20 / .3); }
        .mills-cardcom__who { flex: 1; min-width: 200px; }
        .mills-cardcom__name { font-weight: 600; font-size: .9rem; }
        .mills-cardcom__meta { color: rgb(113 113 122 / 1); font-size: .8rem; }
        .mills-cardcom__card { display: flex; flex-direction: column; align-items: flex-end; }
        .mills-cardcom__masked { font-family: ui-monospace, monospace; font-size: .85rem; }
        .mills-cardcom__when { color: rgb(113 113 122 / 1); font-size: .75rem; }
    </style>
</x-filament::section>
