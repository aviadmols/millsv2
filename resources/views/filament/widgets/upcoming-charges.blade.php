{{--
    Upcoming charges, with one honest toggle.

    Off: only money that will REALLY be charged (active + PayMe + amount known).
    On: adds ACTIVE subscriptions still waiting on a card — the value of the imported
    Cardcom book if everyone updated. The card border turns dashed while it is on, so a
    screenshot can never pass the potential number off as the real one.
--}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.upcoming_heading')">
        {{-- The scope is the HOME TAB's selector (Dashboard filters form) — one choice
             governs every widget on the page, so no checkbox of this widget's own. --}}
        @if ($includeBlocked)
            <p class="mills-upcoming__potential-note">
                {{ __('dashboard.include_blocked_note', ['count' => $blocked]) }}
            </p>
        @endif

        <div class="mills-upcoming">
            @foreach ($stats as $stat)
                <div @class([
                    'mills-upcoming__card',
                    'mills-upcoming__card--potential' => $includeBlocked,
                    'mills-upcoming__card--bad' => $stat['tone'] === 'bad',
                    'mills-upcoming__card--warn' => $stat['tone'] === 'warn',
                ])>
                    <span class="mills-upcoming__label">{{ $stat['label'] }}</span>
                    <span class="mills-upcoming__amount" dir="ltr">₪{{ number_format($stat['metrics']['total'], 2) }}</span>
                    <span class="mills-upcoming__meta">
                        <x-filament::icon :icon="$stat['icon']" class="mills-upcoming__icon" />
                        {{ __('dashboard.charges_pending', ['count' => $stat['metrics']['count']]) }}
                    </span>
                </div>
            @endforeach
        </div>

        @unless ($includeBlocked)
            @if ($blocked > 0 || $unknown > 0)
                <p class="mills-upcoming__blocked">
                    @if ($blocked > 0) {{ __('dashboard.blocked_card', ['count' => $blocked]) }} @endif
                    @if ($blocked > 0 && $unknown > 0) · @endif
                    @if ($unknown > 0) {{ __('dashboard.blocked_amount', ['count' => $unknown]) }} @endif
                </p>
            @endif
        @endunless
    </x-filament::section>

    <style>
        .mills-upcoming { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .75rem; }
        .mills-upcoming__card {
            display: flex; flex-direction: column; gap: .25rem;
            border: 1px solid rgb(228 228 231 / 1); border-radius: .75rem; padding: 1rem;
        }
        .dark .mills-upcoming__card { border-color: rgb(63 63 70 / 1); }

        /* Potential mode: dashed and tinted, so it can never be mistaken for the real book. */
        .mills-upcoming__card--potential { border-style: dashed; border-color: rgb(217 119 6 / .6); }

        .mills-upcoming__card--bad { border-color: rgb(220 38 38 / .5); }
        .mills-upcoming__card--warn { border-color: rgb(217 119 6 / .5); }

        .mills-upcoming__label { font-size: .8125rem; color: rgb(113 113 122 / 1); }
        .mills-upcoming__amount { font-size: 1.5rem; font-weight: 700; color: rgb(24 24 27 / 1); }
        .dark .mills-upcoming__amount { color: rgb(244 244 245 / 1); }

        .mills-upcoming__meta { display: inline-flex; align-items: center; gap: .375rem; font-size: .75rem; color: rgb(113 113 122 / 1); }
        .mills-upcoming__icon { width: 1rem; height: 1rem; }

        .mills-upcoming__toggle { display: inline-flex; align-items: center; gap: .5rem; font-size: .8125rem; color: rgb(82 82 91 / 1); cursor: pointer; }
        .dark .mills-upcoming__toggle { color: rgb(161 161 170 / 1); }

        .mills-upcoming__potential-note {
            margin: 0 0 .75rem; font-size: .8125rem; color: rgb(180 83 9 / 1);
        }

        .mills-upcoming__blocked { margin: .75rem 0 0; font-size: .8125rem; color: rgb(180 83 9 / 1); }
    </style>
</x-filament-widgets::widget>
