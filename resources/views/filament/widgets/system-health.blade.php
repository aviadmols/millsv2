@php
    /*
     * Problems get cards; health gets a chip. The old layout gave every check an
     * identical card, so seven green paragraphs buried the one red line that
     * mattered. Now the eye lands on what is broken — or on a single quiet row
     * of green dots when nothing is.
     */
    $runs = $this->getRecentRuns();
    $statuses = collect($checks)->pluck('status');
    $overall = $statuses->contains('critical') ? 'critical' : ($statuses->contains('warning') ? 'warning' : 'ok');
    $problems = array_values(array_filter($checks, fn ($check) => $check['status'] !== 'ok'));
    $healthy = array_values(array_filter($checks, fn ($check) => $check['status'] === 'ok'));
    $lastRun = $runs[0] ?? null;
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('dashboard.health_heading')"
        :description="__('dashboard.health_description')"
        collapsible
        :collapsed="$overall === 'ok'"
    >
        <x-slot name="afterHeader">
            <span @class([
                'mills-health-pill',
                'mills-health-pill--ok' => $overall === 'ok',
                'mills-health-pill--warn' => $overall === 'warning',
                'mills-health-pill--bad' => $overall === 'critical',
            ])>
                {{ $overall === 'ok' ? __('dashboard.health_all_ok') : __('dashboard.health_attention') }}
            </span>
        </x-slot>

        @if ($problems !== [])
            <div class="mills-health">
                @foreach ($problems as $check)
                    <div @class([
                        'mills-health__item',
                        'mills-health__item--warn' => $check['status'] === 'warning',
                        'mills-health__item--bad' => $check['status'] === 'critical',
                    ])>
                        <div class="mills-health__dot"></div>

                        <div class="mills-health__body">
                            <span class="mills-health__label">{{ $check['label'] }}</span>
                            <div class="mills-health__value">{{ $check['value'] }}</div>

                            @if (! empty($check['help']))
                                {{-- The fix, spelled out. A red light with no instruction is just anxiety. --}}
                                <div class="mills-health__help">{{ $check['help'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($healthy !== [])
            {{-- Everything here is fine — one small chip each, details on hover. --}}
            <div class="mills-health__chips">
                @foreach ($healthy as $check)
                    <span class="mills-health-chip" title="{{ $check['value'] }}">
                        <span class="mills-health-chip__dot"></span>{{ $check['label'] }}
                    </span>
                @endforeach
            </div>
        @endif

        @if ($lastRun)
            <details class="mills-runs">
                <summary class="mills-runs__summary">
                    <span class="mills-runs__title">{{ __('dashboard.health_last_run') }}</span>
                    <span class="mills-runs__cmd">{{ $lastRun['command'] }}</span>
                    <span @class([
                        'mills-health-pill',
                        'mills-health-pill--ok' => $lastRun['status'] === 'completed',
                        'mills-health-pill--bad' => $lastRun['status'] !== 'completed',
                    ])>{{ $lastRun['status'] }}</span>
                    <span class="mills-runs__ago">{{ $lastRun['ago'] }}</span>
                </summary>

                <table class="mills-runs__table">
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td class="mills-runs__cmd">{{ $run['command'] }}</td>
                                <td>
                                    <span @class([
                                        'mills-health-pill',
                                        'mills-health-pill--ok' => $run['status'] === 'completed',
                                        'mills-health-pill--bad' => $run['status'] !== 'completed',
                                    ])>{{ $run['status'] }}</span>
                                </td>
                                <td class="mills-runs__when">{{ $run['ran_at'] }}</td>
                                <td class="mills-runs__ago">{{ $run['ago'] }}</td>
                                <td class="mills-runs__ms">{{ $run['runtime_ms'] ? $run['runtime_ms'].' ms' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @else
            <p class="mills-runs__none">{{ __('dashboard.health_no_runs') }}</p>
        @endif
    </x-filament::section>

    <style>
        .mills-health {
            display: grid; gap: .5rem;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }
        .mills-health__item {
            display: flex; gap: .625rem; align-items: flex-start;
            padding: .625rem .75rem; border-radius: .5rem;
        }
        .mills-health__item--bad  { border: 1px solid rgb(252 165 165 / 1); background: rgb(254 242 242 / .5); }
        .mills-health__item--warn { border: 1px solid rgb(253 230 138 / 1); background: rgb(255 251 235 / .5); }
        .mills-health__dot {
            width: .5rem; height: .5rem; border-radius: 9999px; margin-top: .375rem; flex: none;
        }
        .mills-health__item--warn .mills-health__dot { background: rgb(217 119 6 / 1); }
        .mills-health__item--bad  .mills-health__dot { background: rgb(220 38 38 / 1); }
        .mills-health__label { font-size: .75rem; color: rgb(113 113 122 / 1); }
        .mills-health__value { font-size: .875rem; font-weight: 600; }
        .mills-health__help  { font-size: .75rem; color: rgb(113 113 122 / 1); margin-top: .25rem; max-width: 60ch; }

        .mills-health__chips { display: flex; flex-wrap: wrap; gap: .375rem; }
        .mills-health + .mills-health__chips { margin-top: .75rem; }
        .mills-health-chip {
            display: inline-flex; align-items: center; gap: .375rem;
            font-size: .75rem; padding: .1875rem .625rem; border-radius: 9999px;
            border: 1px solid rgb(228 228 231 / 1); color: rgb(82 82 91 / 1); cursor: default;
        }
        .dark .mills-health-chip { border-color: rgb(63 63 70 / 1); color: rgb(161 161 170 / 1); }
        .mills-health-chip__dot {
            width: .375rem; height: .375rem; border-radius: 9999px; flex: none;
            background: rgb(22 163 74 / 1);
        }

        .mills-health-pill {
            font-size: .75rem; padding: .0625rem .5rem; border-radius: 9999px;
            background: rgb(244 244 245 / 1); color: rgb(63 63 70 / 1);
        }
        .mills-health-pill--ok   { background: rgb(220 252 231 / 1); color: rgb(21 128 61 / 1); }
        .mills-health-pill--warn { background: rgb(254 249 195 / 1); color: rgb(161 98 7 / 1); }
        .mills-health-pill--bad  { background: rgb(254 226 226 / 1); color: rgb(185 28 28 / 1); }

        .mills-runs { margin-top: .75rem; }
        .mills-runs__summary {
            display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
            font-size: .75rem; color: rgb(113 113 122 / 1);
            cursor: pointer; list-style: none; width: fit-content;
        }
        .mills-runs__summary::-webkit-details-marker { display: none; }
        .mills-runs__summary::after { content: '▸'; font-size: .625rem; opacity: .6; }
        .mills-runs[open] .mills-runs__summary::after { content: '▾'; }
        .mills-runs__title { font-weight: 600; }
        .mills-runs__table { width: 100%; font-size: .8125rem; border-collapse: collapse; margin-top: .5rem; }
        .mills-runs__table td { padding: .25rem .5rem; border-top: 1px solid rgb(244 244 245 / 1); }
        .dark .mills-runs__table td { border-color: rgb(63 63 70 / 1); }
        .mills-runs__cmd { font-family: ui-monospace, monospace; }
        .mills-runs__when, .mills-runs__ago, .mills-runs__ms { color: rgb(113 113 122 / 1); white-space: nowrap; }
        .mills-runs__none { font-size: .875rem; color: rgb(161 161 170 / 1); }
    </style>
</x-filament-widgets::widget>
