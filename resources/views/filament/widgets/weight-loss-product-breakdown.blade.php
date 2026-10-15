<x-filament-widgets::widget>
    <style>
        .wl-breakdown .fi-section-header { align-items: flex-end; gap: 24px; }
        .wl-controls { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: flex-end; gap: 12px; }
        .wl-control { display: flex; min-width: 168px; flex-direction: column; gap: 6px; }
        .wl-control-period { min-width: 154px; }
        .wl-control-label { color: #9ca3af; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .wl-select-wrap { position: relative; }
        .wl-select-wrap::after { border-bottom: 2px solid #9ca3af; border-right: 2px solid #9ca3af; content: ''; height: 7px; pointer-events: none; position: absolute; right: 14px; top: 50%; transform: translateY(-70%) rotate(45deg); width: 7px; }
        .wl-select { appearance: none; background: rgba(255, 255, 255, .055); border: 1px solid rgba(255, 255, 255, .14); border-radius: 10px; color: #f9fafb; cursor: pointer; font-size: 14px; font-weight: 600; line-height: 20px; min-height: 42px; outline: none; padding: 10px 40px 10px 13px; transition: border-color .15s ease, box-shadow .15s ease, background .15s ease; width: 100%; }
        .wl-select:hover { background: rgba(255, 255, 255, .08); border-color: rgba(255, 255, 255, .24); }
        .wl-select:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, .14); }
        .wl-custom-badge { align-items: center; align-self: flex-end; background: rgba(245, 158, 11, .12); border: 1px solid rgba(245, 158, 11, .25); border-radius: 999px; color: #fbbf24; display: inline-flex; font-size: 12px; font-weight: 700; min-height: 42px; padding: 0 14px; }
        .wl-metrics { display: grid; gap: 12px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 22px; }
        .wl-metric { background: rgba(255, 255, 255, .035); border: 1px solid rgba(255, 255, 255, .08); border-radius: 12px; padding: 15px 17px; }
        .wl-metric-label { color: #9ca3af; font-size: 12px; font-weight: 650; }
        .wl-metric-value { color: #f9fafb; font-size: 22px; font-weight: 750; letter-spacing: -.02em; margin-top: 5px; }
        .wl-table-shell { border: 1px solid rgba(255, 255, 255, .08); border-radius: 12px; overflow-x: auto; }
        .wl-table { min-width: 620px; width: 100%; }
        .wl-row { align-items: center; display: grid; gap: 24px; grid-template-columns: minmax(170px, 1.4fr) repeat(3, minmax(100px, .7fr)); padding: 15px 18px; }
        .wl-row + .wl-row { border-top: 1px solid rgba(255, 255, 255, .065); }
        .wl-table-head { background: rgba(255, 255, 255, .035); color: #9ca3af; font-size: 11px; font-weight: 750; letter-spacing: .055em; text-transform: uppercase; }
        .wl-dose { color: #f9fafb; font-size: 14px; font-weight: 700; }
        .wl-number { color: #d1d5db; font-size: 14px; text-align: right; }
        .wl-money { color: #f9fafb; font-weight: 700; }
        .wl-total { background: rgba(245, 158, 11, .055); }
        .wl-total .wl-dose, .wl-total .wl-number { color: #fbbf24; font-weight: 750; }
        .wl-empty { color: #9ca3af; padding: 34px 18px; text-align: center; }
        html:not(.dark) .wl-select { background: #fff; border-color: #d1d5db; color: #111827; }
        html:not(.dark) .wl-control-label, html:not(.dark) .wl-metric-label, html:not(.dark) .wl-table-head { color: #6b7280; }
        html:not(.dark) .wl-metric, html:not(.dark) .wl-table-shell { border-color: #e5e7eb; }
        html:not(.dark) .wl-metric, html:not(.dark) .wl-table-head { background: #f9fafb; }
        html:not(.dark) .wl-metric-value, html:not(.dark) .wl-dose, html:not(.dark) .wl-money { color: #111827; }
        html:not(.dark) .wl-number { color: #374151; }
        html:not(.dark) .wl-row + .wl-row { border-color: #e5e7eb; }
        @media (max-width: 900px) { .wl-breakdown .fi-section-header { align-items: stretch; flex-direction: column; } .wl-controls { justify-content: flex-start; width: 100%; } }
        @media (max-width: 640px) { .wl-control { flex: 1 1 140px; min-width: 0; } .wl-metrics { grid-template-columns: 1fr; } }
    </style>

    <x-filament::section class="wl-breakdown">
        <x-slot name="heading">Medication breakdown</x-slot>
        <x-slot name="description">Dose-level sales for the selected medication.</x-slot>

        <x-slot name="afterHeader">
            <div class="wl-controls">
                <label class="wl-control">
                    <span class="wl-control-label">Medication</span>
                    <span class="wl-select-wrap">
                        <select wire:model.live="medication" aria-label="Medication" class="wl-select">
                            @foreach($this->getMedicationOptions() as $slug => $name)
                                <option value="{{ $slug }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </span>
                </label>

                <label class="wl-control wl-control-period">
                    <span class="wl-control-label">Period</span>
                    <span class="wl-select-wrap">
                        <select wire:model.live="period" aria-label="Medication breakdown period" class="wl-select">
                            <option value="quarter">Last 3 months</option>
                            <option value="six_months">Last 6 months</option>
                            <option value="year">Last 12 months</option>
                        </select>
                    </span>
                </label>

                @if(!empty($this->pageFilters['startDate']) || !empty($this->pageFilters['endDate']))
                    <span class="wl-custom-badge">Custom dates active</span>
                @endif
            </div>
        </x-slot>

        @php($products = $this->getProducts())
        @php($totalOrders = $products->sum('orders'))
        @php($totalUnits = $products->sum('units'))
        @php($totalRevenue = $products->sum('revenue'))

        <div class="wl-metrics">
            <div class="wl-metric"><div class="wl-metric-label">Orders</div><div class="wl-metric-value">{{ number_format($totalOrders) }}</div></div>
            <div class="wl-metric"><div class="wl-metric-label">Units sold</div><div class="wl-metric-value">{{ number_format($totalUnits) }}</div></div>
            <div class="wl-metric"><div class="wl-metric-label">Sales</div><div class="wl-metric-value">£{{ number_format($totalRevenue, 2) }}</div></div>
        </div>

        <div class="wl-table-shell">
            <div class="wl-table" role="table" aria-label="Medication dose breakdown">
                <div class="wl-row wl-table-head" role="row">
                    <div role="columnheader">Dose / variation</div>
                    <div role="columnheader" style="text-align:right">Orders</div>
                    <div role="columnheader" style="text-align:right">Units sold</div>
                    <div role="columnheader" style="text-align:right">Sales</div>
                </div>

                @forelse($products as $product)
                    <div class="wl-row" role="row">
                        <div class="wl-dose" role="cell">{{ $product['variation'] }}</div>
                        <div class="wl-number" role="cell">{{ number_format($product['orders']) }}</div>
                        <div class="wl-number" role="cell">{{ number_format($product['units']) }}</div>
                        <div class="wl-number wl-money" role="cell">£{{ number_format($product['revenue'], 2) }}</div>
                    </div>
                @empty
                    <div class="wl-empty">No paid sales for this medication in the selected period.</div>
                @endforelse

                @if($products->isNotEmpty())
                    <div class="wl-row wl-total" role="row">
                        <div class="wl-dose" role="cell">Total</div>
                        <div class="wl-number" role="cell">{{ number_format($totalOrders) }}</div>
                        <div class="wl-number" role="cell">{{ number_format($totalUnits) }}</div>
                        <div class="wl-number" role="cell">£{{ number_format($totalRevenue, 2) }}</div>
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
