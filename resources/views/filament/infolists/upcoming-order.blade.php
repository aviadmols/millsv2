@php
    use App\Support\ShopifyImage;

    /** @var array<string,mixed> $draft */
    $draft = $getState() ?? [];
    $lines = $draft['line_items'] ?? [];
@endphp

@if (empty($draft))
    <p class="fi-in-placeholder">{{ __('subscriptions.no_draft_yet') }}</p>
@else
    <div class="mills-order">
        <div class="mills-order__head">
            @if (! empty($draft['admin_url']))
                <a href="{{ $draft['admin_url'] }}" target="_blank" rel="noopener" class="mills-order__name">
                    {{ $draft['name'] ?? __('subscriptions.draft_order') }}
                </a>
            @else
                <span class="mills-order__name">{{ $draft['name'] ?? __('subscriptions.draft_order') }}</span>
            @endif

            @if (! empty($draft['status']))
                <span class="mills-badge mills-badge--muted">{{ $draft['status'] }}</span>
            @endif

            {{-- The number that matters: this total IS what PayMe will be asked for. --}}
            <span class="mills-order__total mills-order__total--lg">
                ₪{{ number_format((float) ($draft['total'] ?? 0), 2) }}
            </span>
        </div>

        <ul class="mills-lines">
            @forelse ($lines as $line)
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
@endif

<style>
    .mills-order__total--lg { font-size: 1.125rem; }
</style>
