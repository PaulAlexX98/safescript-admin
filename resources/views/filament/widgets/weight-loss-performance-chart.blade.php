<div class="wl-performance-chart">
    <style>
        .wl-performance-chart .fi-wi-chart-canvas-ctn-no-aspect-ratio {
            height: 540px !important;
            max-height: none !important;
        }

        @media (max-width: 768px) {
            .wl-performance-chart .fi-wi-chart-canvas-ctn-no-aspect-ratio {
                height: 420px !important;
            }
        }
    </style>

    @include('filament-widgets::chart-widget')
</div>
