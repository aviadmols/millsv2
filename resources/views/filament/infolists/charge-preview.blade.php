@php
    /*
     * Rendered from two places: the subscription screen passes it as infolist STATE, the
     * order editor passes it as view data. Accepting both is what lets one component be the
     * single account of the money — two copies would be free to drift apart.
     */
    $preview = $preview ?? $getState();
    $money = fn ($v) => '₪'.number_format((float) $v, 2);
@endphp

<div class="mills-preview">
    @if ($preview['lines'] === [] && $preview['unpriced'] === [])
        <p class="fi-in-placeholder">{{ __('subscriptions.no_products') }}</p>
    @else
        <ul class="mills-preview__lines">
            @foreach ($preview['lines'] as $line)
                <li class="mills-preview__line">
                    <span class="mills-preview__title">
                        {{ $line['title'] }}
                        @if ($line['quantity'] > 1)
                            <span class="mills-preview__qty">× {{ $line['quantity'] }}</span>
                        @endif
                        @if ($line['discounted'] && $preview['discount_scope'] === 'matching_lines')
                            <span class="mills-preview__tag">{{ __('subscriptions.preview_discounted') }}</span>
                        @endif
                    </span>
                    <span class="mills-preview__num">{{ $money($line['total']) }}</span>
                </li>
            @endforeach
        </ul>

        <div class="mills-preview__rows">
            <div class="mills-preview__row">
                <span>{{ __('subscriptions.preview_subtotal') }}</span>
                <span class="mills-preview__num">{{ $money($preview['subtotal']) }}</span>
            </div>

            @if ($preview['discount_amount'] > 0)
                <div class="mills-preview__row mills-preview__row--discount">
                    <span>
                        {{ $preview['discount_name'] }}
                        <span class="mills-preview__pct">{{ rtrim(rtrim(number_format($preview['discount_percent'], 2), '0'), '.') }}%</span>
                        @if ($preview['discount_scope'] === 'matching_lines')
                            <span class="mills-preview__scope">{{ __('subscriptions.preview_scope_lines') }}</span>
                        @else
                            <span class="mills-preview__scope">{{ __('subscriptions.preview_scope_order') }}</span>
                        @endif
                    </span>
                    <span class="mills-preview__num">−{{ $money($preview['discount_amount']) }}</span>
                </div>
            @else
                <div class="mills-preview__row mills-preview__row--muted">
                    <span>{{ __('subscriptions.preview_no_discount') }}</span>
                    <span class="mills-preview__num">{{ $money(0) }}</span>
                </div>
            @endif

            <div class="mills-preview__row mills-preview__row--total">
                <span>{{ __('subscriptions.preview_total') }}</span>
                <span class="mills-preview__num">{{ $money($preview['total']) }}</span>
            </div>
        </div>

        {{-- The two warnings that stop a wrong number reaching a customer's card. --}}
        @if ($preview['unpriced'] !== [])
            <p class="mills-preview__warn">
                {{ __('subscriptions.preview_unpriced', ['count' => count($preview['unpriced'])]) }}
            </p>
        @endif

        @if ($preview['stored'] !== null && ! $preview['matches_stored'])
            <p class="mills-preview__warn">
                {{ __('subscriptions.preview_stale', [
                    'stored' => $money($preview['stored']),
                    'computed' => $money($preview['total']),
                ]) }}
            </p>
        @endif
    @endif
</div>

<style>
    .mills-preview { display: flex; flex-direction: column; gap: .75rem; font-size: .875rem; }

    .mills-preview__lines { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .35rem; }

    .mills-preview__line,
    .mills-preview__row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 1rem;
    }

    .mills-preview__num { font-variant-numeric: tabular-nums; white-space: nowrap; }

    .mills-preview__qty,
    .mills-preview__scope { opacity: .6; font-size: .8em; }

    .mills-preview__tag {
        font-size: .72em;
        padding: .05rem .35rem;
        border-radius: 3px;
        background: rgb(34 197 94 / .12);
        color: rgb(21 128 61);
    }

    .dark .mills-preview__tag { color: rgb(134 239 172); }

    .mills-preview__rows {
        display: flex;
        flex-direction: column;
        gap: .35rem;
        border-top: 1px solid rgb(0 0 0 / .1);
        padding-top: .6rem;
    }

    .dark .mills-preview__rows { border-top-color: rgb(255 255 255 / .12); }

    .mills-preview__row--discount { color: rgb(21 128 61); }
    .dark .mills-preview__row--discount { color: rgb(134 239 172); }

    .mills-preview__row--muted { opacity: .6; }

    .mills-preview__row--total {
        font-weight: 700;
        font-size: 1.05rem;
        border-top: 1px solid rgb(0 0 0 / .1);
        padding-top: .5rem;
    }

    .dark .mills-preview__row--total { border-top-color: rgb(255 255 255 / .12); }

    .mills-preview__pct { opacity: .7; }

    .mills-preview__warn {
        margin: 0;
        font-size: .8rem;
        line-height: 1.5;
        color: rgb(180 83 9);
        background: rgb(245 158 11 / .1);
        border-radius: 4px;
        padding: .5rem .65rem;
    }

    .dark .mills-preview__warn { color: rgb(253 186 116); }
</style>
