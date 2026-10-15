<div class="wl-trend-chart">
    <style>
        .wl-trend-chart .fi-wi-chart-canvas-ctn-no-aspect-ratio {
            height: 460px !important;
            max-height: none !important;
        }

        @media (max-width: 768px) {
            .wl-trend-chart .fi-wi-chart-canvas-ctn-no-aspect-ratio {
                height: 380px !important;
            }
        }
    </style>

    @include('filament-widgets::chart-widget')
</div>
