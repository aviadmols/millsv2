@php
    use App\Support\ShopifyImage;

    /** @var list<array<string,mixed>> $lines */
    $lines = $getState() ?? [];
@endphp

@if (empty($lines))
    <p class="fi-in-placeholder">{{ $emptyText ?? __('subscriptions.no_products') }}</p>
@else
    <ul class="mills-lines">
        @foreach ($lines as $line)
            @php
                $image = ShopifyImage::thumb($line['image_url'] ?? null);
                $quantity = (int) ($line['quantity'] ?? 1);
            @endphp

            <li class="mills-line">
                @if ($image)
                    {{-- The image identifies the FLAVOUR, not the portion: variants of one
                         product share a photo. That is why the size always sits beside it. --}}
                    <img src="{{ $image }}" alt="{{ $line['title'] ?? '' }}" class="mills-line__img" loading="lazy">
                @else
                    <div class="mills-line__img mills-line__img--empty">—</div>
                @endif

                <div class="mills-line__body">
                    <div class="mills-line__title">
                        {{ $line['title'] ?? '—' }}
                        @if ($quantity > 1)
                            <span class="mills-line__qty">× {{ $quantity }}</span>
                        @endif
                    </div>

                    <div class="mills-line__meta">
                        @if (! empty($line['grams']))
                            <span>{{ $line['grams'] }} {{ __('subscriptions.grams_per_day') }}</span>
                        @endif
                        @if (! empty($line['pack_size']))
                            <span>{{ $line['pack_size'] }} {{ __('subscriptions.day_pack') }}</span>
                        @endif
                        @if (! empty($line['sku']))
                            <span class="mills-line__sku">{{ $line['sku'] }}</span>
                        @endif
                        @if (! empty($line['warning']))
                            <span class="mills-line__warn">{{ $line['warning'] }}</span>
                        @endif
                    </div>
                </div>

                @if (! empty($line['price']))
                    <div class="mills-line__price">₪{{ number_format((float) $line['price'], 2) }}</div>
                @endif
            </li>
        @endforeach
    </ul>
@endif

<style>
    .mills-lines { display: flex; flex-direction: column; gap: .5rem; margin: 0; padding: 0; list-style: none; }
    .mills-line {
        display: flex; align-items: center; gap: .75rem;
        padding: .5rem .75rem; border: 1px solid rgb(228 228 231 / 1); border-radius: .5rem;
    }
    .dark .mills-line { border-color: rgb(63 63 70 / 1); }
    .mills-line__img {
        width: 44px; height: 44px; flex: none; object-fit: cover;
        border-radius: .375rem; background: rgb(244 244 245 / 1);
    }
    .mills-line__img--empty {
        display: flex; align-items: center; justify-content: center;
        color: rgb(161 161 170 / 1); font-size: .875rem;
    }
    .mills-line__body { min-width: 0; flex: 1 1 auto; }
    .mills-line__title { font-size: .875rem; font-weight: 500; }
    .mills-line__qty { color: rgb(113 113 122 / 1); font-weight: 400; }
    .mills-line__meta {
        display: flex; flex-wrap: wrap; gap: .5rem;
        font-size: .75rem; color: rgb(113 113 122 / 1); margin-top: .125rem;
    }
    .mills-line__sku { font-family: ui-monospace, monospace; }
    .mills-line__warn { color: rgb(180 83 9 / 1); }
    .mills-line__price { font-size: .875rem; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
