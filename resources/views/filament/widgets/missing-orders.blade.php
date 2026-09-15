{{--
    Paid, with no order. Every row is a customer whose card was charged and who will
    receive nothing until somebody creates the order. The reason is shown in Shopify's own
    words where Shopify gave one, because "country is not valid" can be fixed and "failed"
    cannot.
--}}
<x-filament::section>
    <x-slot name="heading">{{ __('dashboard.missing_orders_heading') }}</x-slot>
    <x-slot name="description">{{ __('dashboard.missing_orders_description') }}</x-slot>

    <div class="mills-missing">
        @foreach ($this->getMissing() as $ledger)
            @php $customer = $ledger->subscription?->customer; @endphp

            <div class="mills-missing__row" wire:key="missing-order-{{ $ledger->id }}">
                <div class="mills-missing__who">
                    <div class="mills-missing__name">
                        {{ $customer?->fullName() ?: ($customer?->email ?? '—') }}
                        @if ($ledger->subscription_id)
                            <span class="mills-missing__sub">#{{ $ledger->subscription_id }}</span>
                        @endif
                    </div>
                    <div class="mills-missing__meta">
                        <span class="mills-missing__amount">₪{{ number_format((float) $ledger->amount, 2) }}</span>
                        · {{ $ledger->executed_at?->format('d.m.Y H:i') }}
                    </div>
                </div>

                <div class="mills-missing__reason">
                    {{ $ledger->order_error ?: __('ledgers.order_error_unrecorded') }}
                </div>

                @if ($url = $this->subscriptionUrl($ledger))
                    <x-filament::button tag="a" :href="$url" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">
                        {{ __('dashboard.open') }}
                    </x-filament::button>
                @endif
            </div>
        @endforeach
    </div>

    <style>
        .mills-missing { display: grid; gap: .5rem; }

        .mills-missing__row {
            display: grid;
            grid-template-columns: minmax(10rem, 1fr) minmax(12rem, 2fr) auto;
            gap: 1rem;
            align-items: center;
            padding: .75rem 1rem;
            border-radius: .5rem;
            border: 1px solid rgb(220 38 38 / .25);
            background: rgb(220 38 38 / .04);
        }

        .dark .mills-missing__row { border-color: rgb(252 165 165 / .25); background: rgb(252 165 165 / .05); }

        .mills-missing__name { font-weight: 600; }
        .mills-missing__sub { opacity: .55; font-weight: 400; margin-inline-start: .35rem; font-variant-numeric: tabular-nums; }
        .mills-missing__meta { font-size: .8rem; opacity: .7; font-variant-numeric: tabular-nums; }
        .mills-missing__amount { font-weight: 600; }

        .mills-missing__reason {
            font-size: .82rem;
            line-height: 1.5;
            color: rgb(185 28 28);
            word-break: break-word;
        }

        .dark .mills-missing__reason { color: rgb(252 165 165); }

        @media (max-width: 720px) {
            .mills-missing__row { grid-template-columns: 1fr; gap: .4rem; }
        }
    </style>
</x-filament::section>
