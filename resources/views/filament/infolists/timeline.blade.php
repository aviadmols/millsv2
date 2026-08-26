@php
    use App\Support\Ui\EventPresenter;

    /** @var iterable<\App\Models\ActivityEvent> $events */
    $events = $getState() ?? [];
@endphp

{{--
    The subscription's history as a timeline rather than a table: a rail, a dot per
    event coloured by tone, and the three things a person asks in the order they ask
    them — WHAT happened, WHO did it, WHEN.

    The Blade only ever prints what EventPresenter returns; the raw `details` payload
    (which can carry a buyer_key or a PayMe response) never reaches this file.
--}}
@if (count($events) === 0)
    <p class="fi-in-placeholder">{{ __('activity.empty_heading') }}</p>
@else
    <div class="mills-timeline">
        @foreach ($events as $event)
            @php
                $tone = EventPresenter::tone($event);
                $summary = EventPresenter::summarize($event);
                $note = EventPresenter::note($event);
            @endphp

            <div class="mills-timeline__row">
                <span class="mills-timeline__dot mills-timeline__dot--{{ $tone }}"></span>

                <div class="mills-timeline__body">
                    <span class="mills-timeline__title">{{ EventPresenter::label($event) }}</span>

                    @if ($summary !== '' && $summary !== '—')
                        <span class="mills-timeline__summary">{{ $summary }}</span>
                    @endif

                    {{-- A person's note is prose in their own words: full ink, own line breaks. --}}
                    @if ($note)
                        <p class="mills-timeline__note">{{ $note }}</p>
                    @endif

                    <span class="mills-timeline__meta">
                        <span class="mills-timeline__actor">{{ EventPresenter::actorLabel($event) }}</span>
                        <span dir="ltr">{{ optional($event->created_at)->format('d/m/Y H:i') }}</span>
                        <span class="mills-timeline__ago">{{ optional($event->created_at)?->diffForHumans() }}</span>
                    </span>
                </div>
            </div>
        @endforeach
    </div>
@endif

<style>
    /* The rail runs down the start side so it reads right-to-left in the Hebrew admin
       without a single left/right in this file — logical properties only. */
    .mills-timeline { display: flex; flex-direction: column; }

    .mills-timeline__row {
        position: relative;
        display: flex;
        gap: .75rem;
        padding-block: .625rem;
        padding-inline-start: 1.25rem;
        border-inline-start: 2px solid rgb(228 228 231 / 1);
    }
    .dark .mills-timeline__row { border-inline-start-color: rgb(63 63 70 / 1); }

    /* The last row's rail would otherwise dangle past the final event. */
    .mills-timeline__row:last-child { border-inline-start-color: transparent; }

    .mills-timeline__dot {
        position: absolute;
        inset-inline-start: -5px;
        inset-block-start: 1rem;
        inline-size: 9px;
        block-size: 9px;
        border-radius: 9999px;
        background: rgb(161 161 170 / 1);
    }
    .mills-timeline__dot--success { background: rgb(22 163 74 / 1); }
    .mills-timeline__dot--failure { background: rgb(220 38 38 / 1); }
    .mills-timeline__dot--info    { background: rgb(37 99 235 / 1); }
    .mills-timeline__dot--warning { background: rgb(217 119 6 / 1); }

    .mills-timeline__body { display: flex; flex-direction: column; gap: .125rem; min-width: 0; }

    .mills-timeline__title { font-size: .875rem; font-weight: 600; color: rgb(24 24 27 / 1); }
    .dark .mills-timeline__title { color: rgb(244 244 245 / 1); }

    .mills-timeline__summary { font-size: .8125rem; color: rgb(82 82 91 / 1); }
    .dark .mills-timeline__summary { color: rgb(161 161 170 / 1); }

    .mills-timeline__note {
        font-size: .875rem;
        color: rgb(24 24 27 / 1);
        white-space: pre-wrap;
        margin: .25rem 0 0;
        padding: .5rem .625rem;
        border-radius: .375rem;
        background: rgb(250 250 250 / 1);
        border: 1px solid rgb(228 228 231 / 1);
    }
    .dark .mills-timeline__note {
        color: rgb(244 244 245 / 1);
        background: rgb(39 39 42 / 1);
        border-color: rgb(63 63 70 / 1);
    }

    .mills-timeline__meta {
        display: inline-flex; flex-wrap: wrap; gap: .5rem; align-items: center;
        margin-top: .25rem;
        font-size: .75rem; color: rgb(113 113 122 / 1);
    }
    .mills-timeline__actor {
        padding-block: 1px; padding-inline: .5rem;
        border-radius: 9999px;
        background: rgb(244 244 245 / 1);
        color: rgb(82 82 91 / 1);
    }
    .dark .mills-timeline__actor { background: rgb(39 39 42 / 1); color: rgb(161 161 170 / 1); }

    /* "לפני שעתיים" is the part people actually read; the exact stamp is for disputes. */
    .mills-timeline__ago { opacity: .75; }
</style>
