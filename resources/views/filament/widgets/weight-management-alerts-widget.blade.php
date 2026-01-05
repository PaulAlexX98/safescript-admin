<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Weight management alerts</x-slot>
        <x-slot name="description">Latest 10</x-slot>

        <x-slot name="headerEnd">
            <div class="flex items-center gap-2">
                <x-filament::button size="sm" color="gray" wire:click="addTestAlert">
                    Add test alert
                </x-filament::button>

                <x-filament::button size="sm" color="danger" wire:click="clearAlerts">
                    Clear
                </x-filament::button>
            </div>
        </x-slot>

        <div class="space-y-3" wire:poll.10s>
            @php($alerts = $this->getAlerts())

            @if($alerts->isEmpty())
                <div class="rounded-xl border border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-white/5 p-4">
                    <div class="flex items-start gap-3">
                        <x-filament::icon icon="heroicon-o-bell-alert" class="h-5 w-5 text-gray-500 dark:text-gray-300 mt-0.5" />
                        <div>
                            <div class="text-sm font-medium text-gray-900 dark:text-white">No alerts right now</div>
                            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">Alerts will appear here when a patient has not ordered for 45 days or was registered 3 months ago.</div>
                        </div>
                    </div>
                </div>
            @else
                <style>
                    /* Scoped styles for Weight management alerts */
                    .wm-alerts{display:flex;flex-direction:column;gap:12px}
                    .wm-alert{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:14px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04)}
                    .wm-left{display:flex;align-items:flex-start;gap:12px;min-width:0;flex:1}
                    .wm-ico{margin-top:2px;flex:0 0 auto}
                    .wm-content{min-width:0;flex:1}
                    .wm-top{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
                    .wm-title{font-weight:700;font-size:13px;line-height:1.2;padding:4px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25)}
                    .wm-time{font-size:12px;color:rgba(255,255,255,.62)}
                    .wm-body{margin-top:8px;font-size:14px;line-height:1.45;color:rgba(255,255,255,.88);word-break:break-word}
                    .wm-dismiss{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:rgba(255,255,255,.8);cursor:pointer}
                    .wm-dismiss:hover{background:rgba(255,255,255,.08)}
                    .wm-dismiss:focus{outline:2px solid rgba(255,255,255,.25);outline-offset:2px}
                    .wm-bar{width:4px;border-radius:999px;flex:0 0 auto}
                    .wm-bar-info{background:#38bdf8}
                    .wm-bar-warning{background:#f59e0b}
                    .wm-bar-danger{background:#ef4444}
                    .wm-bar-success{background:#10b981}
                    .wm-actions{display:flex;align-items:center;gap:10px}
                    .wm-view{display:inline-flex;align-items:center;gap:8px;padding:0 12px;height:34px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:rgba(255,255,255,.88);font-weight:700;font-size:13px;text-decoration:none;cursor:pointer}
                    .wm-view:hover{background:rgba(255,255,255,.08)}
                    .wm-view:focus{outline:2px solid rgba(255,255,255,.25);outline-offset:2px}
                </style>

                <div class="wm-alerts">
                    @foreach($alerts as $a)
                        @php($kind = $a['kind'] ?? 'info')
                        @php($barClass = $kind === 'warning' ? 'wm-bar-warning' : ($kind === 'danger' ? 'wm-bar-danger' : ($kind === 'success' ? 'wm-bar-success' : 'wm-bar-info')))

                        <div class="wm-alert">
                            <div class="wm-left">
                                <span class="wm-bar {{ $barClass }}"></span>

                                <div class="wm-ico">
                                    @if($kind === 'warning')
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-amber-400" />
                                    @elseif($kind === 'danger')
                                        <x-filament::icon icon="heroicon-o-exclamation-circle" class="h-5 w-5 text-red-400" />
                                    @elseif($kind === 'success')
                                        <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-emerald-400" />
                                    @else
                                        <x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5 text-sky-400" />
                                    @endif
                                </div>

                                <div class="wm-content">
                                    <div class="wm-top">
                                        <span class="wm-title">{{ $a['title'] ?? 'Alert' }}</span>
                                        <span class="wm-time">{{ ($a['created_at'] ?? now())->diffForHumans() }}</span>
                                    </div>

                                    <div class="wm-body">{{ $a['body'] ?? '' }}</div>
                                </div>
                            </div>

                            <div class="wm-actions">
                                @if(!empty($a['order_url']))
                                    <a href="{{ $a['order_url'] }}" target="_blank" rel="noopener" class="wm-view">
                                        View
                                        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
                                    </a>
                                @endif

                                @if(!empty($a['id']))
                                    <button
                                        type="button"
                                        class="wm-dismiss"
                                        title="Dismiss"
                                        aria-label="Dismiss"
                                        wire:click="dismissAlert({{ (int) $a['id'] }})"
                                    >
                                        <span aria-hidden="true" style="font-size:18px;line-height:1;">×</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>