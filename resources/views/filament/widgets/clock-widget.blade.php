<x-filament-widgets::widget>
    <x-filament::section>
        @php
            $u = auth()->user();
            $shift = $this->getOpenShift();

            $displayName = $u?->pharmacist_display_name
                ?: $u?->name
                ?: trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? ''));

            $reg = $u?->gphc_number ?: null;
            $today = now()->format('d M Y');
        @endphp

        <div class="flex flex-col gap-3">
            <div class="flex w-full flex-col gap-4 sm:flex-row sm:items-center sm:justify-between sm:gap-8">
                <div class="min-w-0 flex-1 sm:pr-6">
                    <div class="text-xs text-gray-500">Time clock</div>

                    <div class="mt-1 flex flex-wrap items-center">
                        <div class="truncate text-base font-semibold text-gray-900 dark:text-white">
                            {{ $displayName ?: '—' }}
                        </div>
                        <span style="display:inline-block;width:16px;height:1px"></span>

                        @if($reg)
                            <x-filament::badge size="sm" color="gray" class="whitespace-nowrap">
                                {{ $reg }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge size="sm" color="warning" class="whitespace-nowrap">
                                Reg missing
                            </x-filament::badge>
                        @endif
                        <span style="display:inline-block;width:16px;height:1px"></span>

                        <x-filament::badge size="sm" color="gray" class="whitespace-nowrap">
                            {{ $today }}
                        </x-filament::badge>
                        <span style="display:inline-block;width:16px;height:1px"></span>

                        @if($shift)
                            <x-filament::badge size="sm" color="success" class="whitespace-nowrap">
                                In {{ $shift->clocked_in_at?->format('H:i') }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge size="sm" color="gray" class="whitespace-nowrap">
                                Not clocked in
                            </x-filament::badge>
                        @endif
                    </div>

                    <div class="mt-1 text-xs text-gray-500">
                        @if($shift)
                            Started at {{ $shift->clocked_in_at?->format('H:i:s') }}
                        @else
                            Use Clock in to start your shift
                        @endif
                    </div>
                </div>

                <div class="flex shrink-0 items-center sm:ml-auto sm:justify-end space-x-6">
                    <x-filament::button
                        size="sm"
                        wire:click="clockIn"
                        :disabled="$shift !== null"
                        icon="heroicon-m-play"
                        class="min-w-[120px] justify-center"
                    >
                        Clock in
                    </x-filament::button>
                    <span style="display:inline-block;width:24px"></span>

                    <x-filament::button
                        size="sm"
                        color="gray"
                        wire:click="clockOut"
                        :disabled="$shift === null"
                        icon="heroicon-m-stop"
                        class="min-w-[120px] justify-center"
                    >
                        Clock out
                    </x-filament::button>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>