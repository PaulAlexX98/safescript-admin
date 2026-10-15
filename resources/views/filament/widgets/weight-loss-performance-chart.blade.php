<x-filament-widgets::widget>
    <style>
        .wl-outcomes .fi-section-header { align-items: flex-end; gap: 20px; }
        .wl-outcomes-controls { display: flex; align-items: flex-end; gap: 10px; }
        .wl-outcomes-control { display: flex; min-width: 170px; flex-direction: column; gap: 6px; }
        .wl-outcomes-label { color: #9ca3af; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .wl-outcomes-select-wrap { position: relative; }
        .wl-outcomes-select-wrap::after { border-bottom: 2px solid #9ca3af; border-right: 2px solid #9ca3af; content: ''; height: 7px; pointer-events: none; position: absolute; right: 14px; top: 50%; transform: translateY(-70%) rotate(45deg); width: 7px; }
        .wl-outcomes-select { appearance: none; background: rgba(255, 255, 255, .055); border: 1px solid rgba(255, 255, 255, .14); border-radius: 10px; color: #f9fafb; cursor: pointer; font-size: 14px; font-weight: 600; min-height: 42px; outline: none; padding: 10px 40px 10px 13px; width: 100%; }
        .wl-outcomes-select:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, .14); }
        .wl-outcomes-custom { align-items: center; background: rgba(245, 158, 11, .12); border: 1px solid rgba(245, 158, 11, .25); border-radius: 999px; color: #fbbf24; display: inline-flex; font-size: 12px; font-weight: 700; min-height: 38px; padding: 0 13px; }
        .wl-outcomes-grid { display: grid; gap: 14px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .wl-outcome-card { background: rgba(255, 255, 255, .032); border: 1px solid rgba(255, 255, 255, .085); border-radius: 14px; min-height: 150px; overflow: hidden; padding: 17px; position: relative; }
        .wl-outcome-card::before { background: var(--accent); content: ''; height: 3px; left: 17px; position: absolute; top: 0; width: 38px; }
        .wl-outcome-top { align-items: center; display: flex; gap: 9px; justify-content: space-between; }
        .wl-outcome-name { color: #f9fafb; font-size: 15px; font-weight: 750; }
        .wl-patient-badge { background: color-mix(in srgb, var(--accent) 15%, transparent); border: 1px solid color-mix(in srgb, var(--accent) 32%, transparent); border-radius: 999px; color: var(--accent); font-size: 11px; font-weight: 750; padding: 4px 9px; white-space: nowrap; }
        .wl-outcome-value { color: #f9fafb; font-size: 27px; font-weight: 800; letter-spacing: -.03em; margin-top: 22px; }
        .wl-outcome-caption { color: #9ca3af; font-size: 12px; margin-top: 4px; }
        .wl-outcome-footer { color: #9ca3af; font-size: 12px; margin-top: 15px; }
        .wl-outcome-empty { color: #9ca3af; font-size: 13px; line-height: 1.5; margin-top: 26px; }
        html:not(.dark) .wl-outcomes-select { background: #fff; border-color: #d1d5db; color: #111827; }
        html:not(.dark) .wl-outcome-card { background: #f9fafb; border-color: #e5e7eb; }
        html:not(.dark) .wl-outcome-name, html:not(.dark) .wl-outcome-value { color: #111827; }
        @media (max-width: 1100px) { .wl-outcomes-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 700px) { .wl-outcomes .fi-section-header { align-items: stretch; flex-direction: column; } .wl-outcomes-controls, .wl-outcomes-control { width: 100%; } .wl-outcomes-grid { grid-template-columns: 1fr; } }
    </style>

    <x-filament::section class="wl-outcomes">
        <x-slot name="heading">Patient weight outcomes</x-slot>
        <x-slot name="description">Average change from each patient's first recorded weight to their latest follow-up.</x-slot>

        <x-slot name="afterHeader">
            <div class="wl-outcomes-controls">
                @if(empty($this->pageFilters['startDate']) && empty($this->pageFilters['endDate']))
                    <label class="wl-outcomes-control">
                        <span class="wl-outcomes-label">Period</span>
                        <span class="wl-outcomes-select-wrap">
                            <select wire:model.live="period" aria-label="Patient outcomes period" class="wl-outcomes-select">
                                <option value="quarter">Last 3 months</option>
                                <option value="six_months">Last 6 months</option>
                                <option value="year">Last 12 months</option>
                            </select>
                        </span>
                    </label>
                @else
                    <span class="wl-outcomes-custom">Custom dates active</span>
                @endif
            </div>
        </x-slot>

        @php
            $performance = $this->getPerformance();
            $treatments = [
                'mounjaro' => ['name' => 'Mounjaro', 'colour' => '#f59e0b'],
                'wegovy_injection' => ['name' => 'Wegovy injection', 'colour' => '#38bdf8'],
                'wegovy_pill' => ['name' => 'Wegovy pill', 'colour' => '#a78bfa'],
                'foundayo' => ['name' => 'Foundayo', 'colour' => '#f472b6'],
            ];
        @endphp

        <div class="wl-outcomes-grid">
            @foreach($treatments as $key => $treatment)
                @php
                    $result = $performance[$key];
                    $change = $result['average_loss_kg'];
                    $isLoss = $change !== null && $change > 0;
                    $isGain = $change !== null && $change < 0;
                @endphp
                <div class="wl-outcome-card" style="--accent: {{ $treatment['colour'] }}">
                    <div class="wl-outcome-top">
                        <div class="wl-outcome-name">{{ $treatment['name'] }}</div>
                        <span class="wl-patient-badge">{{ number_format($result['patients']) }} {{ Str::plural('patient', $result['patients']) }}</span>
                    </div>

                    @if($change !== null)
                        <div class="wl-outcome-value">{{ number_format(abs($change), 1) }} kg {{ $isLoss ? 'lost' : ($isGain ? 'gained' : 'change') }}</div>
                        <div class="wl-outcome-caption">Average change across included patients</div>
                        <div class="wl-outcome-footer">
                            {{ number_format(abs($result['average_loss_percent']), 1) }}% average {{ $result['average_loss_percent'] >= 0 ? 'loss' : 'gain' }}
                            · {{ number_format($result['losing_percent']) }}% lost weight
                        </div>
                    @else
                        <div class="wl-outcome-empty">No patients have both a starting weight and a later follow-up in this period.</div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
