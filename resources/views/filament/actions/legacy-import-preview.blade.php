{{--
    What the import is about to create — shown BEFORE it is created.

    Picking the wrong row in the search box makes a live subscription for a stranger, so this
    spells out the customer, the plan and every dog. The date shown is the one that will
    actually be stored (rolled forward off the note's stale delivery date), not the raw value
    in the note — showing the note's date would tell the admin the customer is overdue when
    they are not.
--}}
@php
    $status = $preview['status'] ?? 'not_found';
    $customer = $preview['customer'] ?? [];
    $note = $preview['note'] ?? null;
    $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
@endphp

<div class="fi-sc-has-gap">
    @if ($status === 'not_found')
        <p class="fi-color-danger">{{ __('customers.import_not_found') }}</p>
    @else
        <p>
            <strong>{{ $name !== '' ? $name : '—' }}</strong>
            @if (filled($customer['email'] ?? null))
                · {{ $customer['email'] }}
            @endif
            @if (filled($customer['phone'] ?? null))
                · {{ $customer['phone'] }}
            @endif
        </p>

        @if ($status === 'already_has_subscription')
            <x-filament::badge color="warning">{{ __('customers.import_already_has_subscription') }}</x-filament::badge>
        @elseif ($note === null)
            <x-filament::badge color="warning">{{ __('customers.import_no_note') }}</x-filament::badge>
        @else
            <x-filament::badge color="danger">{{ __('customers.preview_needs_card_update') }}</x-filament::badge>

            <p>
                {{ $note['frequency_months'] === 2 ? __('subscriptions.every_2_months') : __('subscriptions.monthly') }}
                @if (filled($preview['next_charge_at'] ?? null))
                    · {{ __('subscriptions.next_charge') }}: {{ $preview['next_charge_at'] }}
                @endif
                @if ($note['discount_percent'] !== null)
                    · {{ __('subscriptions.discount_percent') }}: {{ $note['discount_percent'] }}%
                @endif
            </p>

            <ul>
                @foreach ($note['dogs'] as $dog)
                    <li>
                        <strong>{{ $dog['name'] !== '' ? $dog['name'] : __('subscriptions.dogs') }}</strong>
                        @if (filled($dog['weight'])) · {{ $dog['weight'] }} kg @endif
                        @if (filled($dog['age'])) · {{ __('subscriptions.age') }} {{ $dog['age'] }} @endif
                        @if (filled($dog['allergies'])) · {{ __('subscriptions.allergies') }}: {{ $dog['allergies'] }} @endif
                        · {{ count($dog['variants']) }} {{ __('subscriptions.products') }}
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
