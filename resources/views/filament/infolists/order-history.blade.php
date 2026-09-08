@php
    use App\Support\ShopifyImage;

    /** @var list<array<string,mixed>> $orders */
    $orders = $getState() ?? [];
@endphp

@if (empty($orders))
    <p class="fi-in-placeholder">{{ __('subscriptions.no_orders') }}</p>
@else
    <div class="mills-orders">
        @foreach ($orders as $order)
            @php
                $paid = ($order['financial_status'] ?? null) === 'PAID';
            @endphp

            <div class="mills-order">
                <div class="mills-order__head">
                    @if (! empty($order['admin_url']))
                        {{-- The link is built in the service, not here: computing it in a
                             closure is what made every order point at /orders/1. --}}
                        <a href="{{ $order['admin_url'] }}" target="_blank" rel="noopener" class="mills-order__name">
                            {{ $order['name'] ?? '—' }}
                        </a>
                    @else
                        <span class="mills-order__name">{{ $order['name'] ?? '—' }}</span>
                    @endif

                    @if (! empty($order['created_at']))
                        <span class="mills-order__date">
                            {{ \Illuminate\Support\Carbon::parse($order['created_at'])->format('Y-m-d') }}
                        </span>
                    @endif

                    <span @class(['mills-badge', 'mills-badge--ok' => $paid, 'mills-badge--warn' => ! $paid])>
                        {{ $order['financial_status'] ?? '—' }}
                    </span>

                    @if (! empty($order['fulfillment_status']))
                        <span class="mills-badge mills-badge--muted">{{ $order['fulfillment_status'] }}</span>
                    @endif

                    <span class="mills-order__total">
                        ₪{{ number_format((float) ($order['total'] ?? 0), 2) }}
                    </span>

                    {{--
                        Asked where the order is. The action itself lives on the page and
                        receives THIS order's id, so "cancel this one" needs no second step
                        asking which. Hidden once Shopify says it is already cancelled.
                    --}}
                    @if (! empty($order['id']) && empty($order['cancelled_at']))
                        <button
                            type="button"
                            class="mills-order__cancel"
                            wire:click="mountAction('cancelOrder', { order_id: '{{ $order['id'] }}', order_name: @js($order['name'] ?? '') })"
                            wire:loading.attr="disabled"
                        >
                            {{ __('subscriptions.cancel_order') }}
                        </button>
                    @endif
                </div>

                <ul class="mills-lines">
                    @forelse ($order['line_items'] ?? [] as $line)
                        @php $image = ShopifyImage::thumb($line['image_url'] ?? null); @endphp

                        <li class="mills-line">
                            @if ($image)
                                <img src="{{ $image }}" alt="{{ $line['title'] ?? '' }}" class="mills-line__img" loading="lazy">
                            @else
                                <div class="mills-line__img mills-line__img--empty">—</div>
                            @endif

                            <div class="mills-line__body">
                                <div class="mills-line__title">
                                    {{ $line['title'] ?? '—' }}
                                    @if ((int) ($line['quantity'] ?? 1) > 1)
                                        <span class="mills-line__qty">× {{ (int) $line['quantity'] }}</span>
                                    @endif
                                </div>
                                @if (! empty($line['sku']))
                                    <div class="mills-line__meta"><span class="mills-line__sku">{{ $line['sku'] }}</span></div>
                                @endif
                            </div>

                            @if (! empty($line['price']))
                                <div class="mills-line__price">₪{{ number_format((float) $line['price'], 2) }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="mills-line mills-line--empty">{{ __('subscriptions.no_products') }}</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>
@endif

<style>
    .mills-orders { display: flex; flex-direction: column; gap: .75rem; }
    .mills-order { border: 1px solid rgb(228 228 231 / 1); border-radius: .5rem; padding: .75rem; }
    .dark .mills-order { border-color: rgb(63 63 70 / 1); }
    .mills-order__head {
        display: flex; align-items: center; flex-wrap: wrap; gap: .5rem;
        margin-bottom: .5rem; font-size: .875rem;
    }
    .mills-order__name { font-weight: 600; color: rgb(180 83 9 / 1); text-decoration: none; }
    .mills-order__name:hover { text-decoration: underline; }
    .mills-order__date { color: rgb(113 113 122 / 1); }
    .mills-order__total { margin-inline-start: auto; font-variant-numeric: tabular-nums; font-weight: 600; }

    /* Quiet until wanted: destructive, so it should not compete with the order's own numbers. */
    .mills-order__cancel {
        font-size: .75rem;
        padding: .15rem .55rem;
        border-radius: 6px;
        border: 1px solid rgb(220 38 38 / .35);
        color: rgb(185 28 28);
        background: transparent;
        cursor: pointer;
        white-space: nowrap;
        transition: background .15s ease, border-color .15s ease;
    }

    .mills-order__cancel:hover { background: rgb(220 38 38 / .08); border-color: rgb(220 38 38 / .6); }
    .mills-order__cancel:disabled { opacity: .5; cursor: default; }
    .dark .mills-order__cancel { color: rgb(252 165 165); border-color: rgb(252 165 165 / .35); }
    .mills-badge {
        font-size: .75rem; padding: .0625rem .5rem; border-radius: 9999px;
        background: rgb(244 244 245 / 1); color: rgb(63 63 70 / 1);
    }
    .mills-badge--ok { background: rgb(220 252 231 / 1); color: rgb(21 128 61 / 1); }
    .mills-badge--warn { background: rgb(254 249 195 / 1); color: rgb(161 98 7 / 1); }
    .mills-badge--muted { background: rgb(244 244 245 / 1); color: rgb(113 113 122 / 1); }
    .mills-line--empty { color: rgb(161 161 170 / 1); font-size: .875rem; justify-content: center; }
</style>
