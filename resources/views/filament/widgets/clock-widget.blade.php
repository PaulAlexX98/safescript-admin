<x-filament-widgets::widget>
    {{-- CLOCK_WIDGET_V2 --}}
    <x-filament::section>
        @php
            $u = auth()->user();
            $shift = $this->getOpenShift();

            $today = now()->format('d M Y');
        @endphp

        <div class="flex flex-col gap-3">
            <div class="flex w-full flex-col gap-4 sm:flex-row sm:items-center sm:justify-between sm:gap-8">
                <div class="min-w-0 flex-1 sm:pr-6">
                    <div class="text-xs text-gray-500">Time clock</div>
                    <div class="mt-2"></div>

                    <div class="space-y-4 max-w-[760px]">
                        <div class="grid gap-1.5">
                            <div class="text-xs text-gray-500">Name</div>
                            <div class="flex flex-wrap items-center gap-3">
                                <input
                                    type="text"
                                    wire:model.defer="pharmacist_name"
                                    placeholder="Enter pharmacist name"
                                    style="background:#fff;color:#000;"
                                    class="w-[320px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-black shadow-sm placeholder:text-gray-500 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/25 dark:border-gray-600 dark:bg-white dark:text-black"
                                />
                                <x-filament::badge size="sm" color="gray" class="whitespace-nowrap">
                                    {{ ($this->pharmacist_name ?? null) ? $this->pharmacist_name : '—' }}
                                </x-filament::badge>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <div class="text-xs text-gray-500">GPhC</div>
                            <div class="flex flex-wrap items-center gap-3">
                                <input
                                    type="text"
                                    wire:model.defer="pharmacist_reg"
                                    placeholder="Enter GPhC number"
                                    style="background:#fff;color:#000;"
                                    class="w-[320px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-black shadow-sm placeholder:text-gray-500 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/25 dark:border-gray-600 dark:bg-white dark:text-black"
                                />
                                @if(($this->pharmacist_reg ?? null))
                                    <x-filament::badge size="sm" color="gray" class="whitespace-nowrap">
                                        {{ $this->pharmacist_reg }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge size="sm" color="warning" class="whitespace-nowrap">
                                        Reg missing
                                    </x-filament::badge>
                                @endif

                                <x-filament::badge size="sm" color="gray" class="whitespace-nowrap">
                                    {{ $today }}
                                </x-filament::badge>

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
                        </div>
                    </div>

                    <div class="mt-4 text-xs text-gray-500">
                        @if($shift)
                            Started at {{ $shift->clocked_in_at?->format('H:i:s') }}
                        @else
                            Use Clock in to start your shift
                        @endif
                    </div>
                </div>

                <div class="flex shrink-0 sm:ml-auto">
                    <div class="w-full sm:w-auto rounded-xl border border-gray-700/60 bg-gray-900/40 p-4 shadow-sm backdrop-blur">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 sm:gap-10">
                            <div class="">
                                <div class="text-xs font-semibold text-gray-200">Start time</div>
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                                    <input
                                        type="time"
                                        min="09:00"
                                        max="18:00"
                                        step="300"
                                        wire:model.defer="start_time"
                                        style="color-scheme: dark;"
                                        class="w-[220px] rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/25 mr-6"
                                    />

                                    <x-filament::button
                                        size="sm"
                                        wire:click="clockIn"
                                        :disabled="$shift !== null"
                                        icon="heroicon-m-play"
                                        class="min-w-[120px] justify-center mt-0"
                                    >
                                        Sign in
                                    </x-filament::button>
                                </div>
                            </div>

                            <div class="">
                                <div class="text-xs font-semibold text-gray-200">End time</div>
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                                    <input
                                        type="time"
                                        min="09:00"
                                        max="18:00"
                                        step="300"
                                        wire:model.defer="end_time"
                                        style="color-scheme: dark;"
                                        class="w-[220px] rounded-lg border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/25 mr-6"
                                    />

                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        wire:click="clockOut"
                                        :disabled="$shift === null"
                                        icon="heroicon-m-stop"
                                        class="min-w-[120px] justify-center mt-0"
                                    >
                                        Sign out
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>

                       
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>