<x-filament-widgets::widget>
    <style>
        .wl-overview-head { align-items: center; display: flex; gap: 20px; justify-content: space-between; margin-bottom: 13px; }
        .wl-overview-title { color: #f9fafb; font-size: 16px; font-weight: 750; }
        .wl-overview-subtitle { color: #9ca3af; font-size: 12px; margin-top: 2px; }
        .wl-overview-select { appearance: none; background: rgba(255,255,255,.055); border: 1px solid rgba(255,255,255,.14); border-radius: 9px; color: #f9fafb; cursor: pointer; font-size: 13px; font-weight: 650; min-height: 38px; outline: none; padding: 8px 34px 8px 12px; }
        .wl-overview-select-wrap { position: relative; }
        .wl-overview-select-wrap::after { border-bottom: 2px solid #9ca3af; border-right: 2px solid #9ca3af; content: ''; height: 6px; pointer-events: none; position: absolute; right: 13px; top: 45%; transform: rotate(45deg); width: 6px; }
        .wl-kpi-grid { display: grid; gap: 11px; grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .wl-kpi { background: linear-gradient(145deg, rgba(255,255,255,.055), rgba(255,255,255,.025)); border: 1px solid rgba(255,255,255,.085); border-radius: 12px; min-width: 0; padding: 14px 15px; position: relative; }
        .wl-kpi::before { background: var(--wl-accent); border-radius: 999px; content: ''; height: 3px; left: 15px; opacity: .9; position: absolute; top: 0; width: 28px; }
        .wl-kpi-top { align-items: center; display: flex; gap: 8px; }
        .wl-kpi-icon { align-items: center; background: color-mix(in srgb, var(--wl-accent) 14%, transparent); border-radius: 8px; color: var(--wl-accent); display: inline-flex; flex: 0 0 auto; height: 28px; justify-content: center; width: 28px; }
        .wl-kpi-icon svg { height: 15px; width: 15px; }
        .wl-kpi-label { color: #9ca3af; font-size: 11px; font-weight: 700; line-height: 1.25; }
        .wl-kpi-value { color: #f9fafb; font-size: 25px; font-weight: 780; letter-spacing: -.035em; line-height: 1.1; margin-top: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wl-kpi-note { color: #6b7280; font-size: 10px; line-height: 1.3; margin-top: 4px; }
        html:not(.dark) .wl-overview-title, html:not(.dark) .wl-kpi-value { color: #111827; }
        html:not(.dark) .wl-overview-select { background: #fff; border-color: #d1d5db; color: #111827; }
        html:not(.dark) .wl-kpi { background: #fff; border-color: #e5e7eb; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        @media (max-width: 1280px) { .wl-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .wl-overview-head { align-items: flex-start; flex-direction: column; } .wl-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>

    @php($stats = $this->getSummary())

    <div class="wl-overview-head">
        <div>
            <div class="wl-overview-title">Weight-loss activity</div>
            <div class="wl-overview-subtitle">Paid patient, dispensing, and revenue totals for the selected period</div>
        </div>

        @if(empty($this->pageFilters['startDate']) && empty($this->pageFilters['endDate']))
            <span class="wl-overview-select-wrap">
                <select wire:model.live="period" aria-label="Overview period" class="wl-overview-select">
                    <option value="month">This month</option>
                    <option value="quarter">Last 3 months</option>
                    <option value="six_months">Last 6 months</option>
                    <option value="year">Last 12 months</option>
                </select>
            </span>
        @endif
    </div>

    <div class="wl-kpi-grid">
        <div class="wl-kpi" style="--wl-accent:#34d399">
            <div class="wl-kpi-top"><span class="wl-kpi-icon"><x-filament::icon icon="heroicon-o-user-plus" /></span><span class="wl-kpi-label">New patients</span></div>
            <div class="wl-kpi-value">{{ number_format($stats['new_patients']) }}</div>
            <div class="wl-kpi-note">First paid treatment orders</div>
        </div>
        <div class="wl-kpi" style="--wl-accent:#60a5fa">
            <div class="wl-kpi-top"><span class="wl-kpi-icon"><x-filament::icon icon="heroicon-o-shopping-bag" /></span><span class="wl-kpi-label">Paid orders</span></div>
            <div class="wl-kpi-value">{{ number_format($stats['paid_orders']) }}</div>
            <div class="wl-kpi-note">All paid service orders</div>
        </div>
        <div class="wl-kpi" style="--wl-accent:#a78bfa">
            <div class="wl-kpi-top"><span class="wl-kpi-icon"><x-filament::icon icon="heroicon-o-cube" /></span><span class="wl-kpi-label">Medication units</span></div>
            <div class="wl-kpi-value">{{ number_format($stats['units']) }}</div>
            <div class="wl-kpi-note">Treatment units dispensed</div>
        </div>
        <div class="wl-kpi" style="--wl-accent:#f59e0b">
            <div class="wl-kpi-top"><span class="wl-kpi-icon"><x-filament::icon icon="heroicon-o-beaker" /></span><span class="wl-kpi-label">Mounjaro</span></div>
            <div class="wl-kpi-value">{{ number_format($stats['mounjaro_units']) }}</div>
            <div class="wl-kpi-note">Units sold</div>
        </div>
        <div class="wl-kpi" style="--wl-accent:#22d3ee">
            <div class="wl-kpi-top"><span class="wl-kpi-icon"><x-filament::icon icon="heroicon-o-beaker" /></span><span class="wl-kpi-label">Wegovy</span></div>
            <div class="wl-kpi-value">{{ number_format($stats['wegovy_units']) }}</div>
            <div class="wl-kpi-note">Injection and pill units</div>
        </div>
        <div class="wl-kpi" style="--wl-accent:#fbbf24">
            <div class="wl-kpi-top"><span class="wl-kpi-icon"><x-filament::icon icon="heroicon-o-currency-pound" /></span><span class="wl-kpi-label">Medication sales</span></div>
            <div class="wl-kpi-value">£{{ number_format($stats['revenue'], 2) }}</div>
            <div class="wl-kpi-note">Paid medication value</div>
        </div>
    </div>
</x-filament-widgets::widget>
