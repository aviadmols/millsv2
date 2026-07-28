<x-filament-panels::page>
    <form wire:submit="open" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
                {{ __('portal.open') }}
            </x-filament::button>
        </div>
    </form>

    @if ($portalUrl)
        <x-filament::section>
            <x-slot name="heading">{{ $foundName }}</x-slot>
            <x-slot name="description">{{ __('portal.read_only_note') }}</x-slot>

            <div class="fi-sc-has-gap">
                <x-filament::button
                    tag="a"
                    href="{{ $portalUrl }}"
                    target="_blank"
                    rel="noopener"
                    icon="heroicon-o-arrow-top-right-on-square"
                >
                    {{ __('portal.open_in_tab') }}
                </x-filament::button>

                {{-- The raw link, so it can be copied into another browser or profile. --}}
                <x-filament::input.wrapper class="fi-mt-3">
                    <x-filament::input type="text" :value="$portalUrl" readonly />
                </x-filament::input.wrapper>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
