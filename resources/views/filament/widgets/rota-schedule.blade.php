@php
    use Carbon\Carbon;

    $start = Carbon::parse('2026-02-23')->startOfDay();
    $end   = Carbon::parse('2026-03-04')->endOfDay();

    // Fixed display (rota only)
    $displayName = 'Wasim Malik';
    $reg = '2066988';

    // Planned hours
    $plannedStart = '09:00';
    $plannedEnd = '18:00';
@endphp

<x-filament::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
            <div class="text-sm font-semibold text-gray-900 dark:text-white">Rota Schedule</div>
            <div class="mt-1 text-xs text-gray-500">Schedule view only.</div>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <span class="font-medium">Name</span> <span>{{ $displayName }}</span>
                <span class="mx-2 text-gray-400">|</span>
                <span class="font-medium">Registration</span> <span>{{ $reg }}</span>
                <span class="mx-2 text-gray-400">|</span>
                <span class="font-medium">Hours</span> <span>{{ $plannedStart }}–{{ $plannedEnd }}</span>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-white/10">
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Day</th>
                            <th class="px-4 py-3">Start</th>
                            <th class="px-4 py-3">End</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @for ($d = $start->copy(); $d->lte($end); $d->addDay())
                            @continue($d->isSaturday() || $d->isSunday())

                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $d->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $d->format('l') }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $plannedStart }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $plannedEnd }}</td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament::page>