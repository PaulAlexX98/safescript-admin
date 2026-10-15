<?php

namespace App\Filament\Widgets;

use App\Support\WeightLossOverview;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class WeightLossPerformance extends ChartWidget
{
    use InteractsWithPageFilters;

    public ?string $filter = '6';

    protected string $view = 'filament.widgets.weight-loss-performance-chart';

    protected ?string $heading = 'Patient weight outcomes by treatment';

    protected ?string $description = 'Average change from baseline to latest follow-up. Each included patient has both a baseline and a later weight.';

    protected ?string $maxHeight = '440px';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getFilters(): ?array
    {
        if (! empty($this->pageFilters['startDate']) || ! empty($this->pageFilters['endDate'])) {
            return null;
        }

        return [
            '3' => 'Last 3 months',
            '6' => 'Last 6 months',
            '12' => 'Last 12 months',
        ];
    }

    protected function getData(): array
    {
        $performance = WeightLossOverview::treatmentPerformance(
            match ($this->filter) {
                '3' => 'quarter',
                '12' => 'year',
                default => 'six_months',
            },
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );

        $keys = ['mounjaro', 'wegovy_injection', 'wegovy_pill', 'foundayo'];
        $labels = ['Mounjaro', 'Wegovy injection', 'Wegovy pill', 'Foundayo'];

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Average weight loss (kg)',
                    'data' => array_map(
                        static fn (string $key): ?float => $performance[$key]['average_loss_kg'],
                        $keys,
                    ),
                    'backgroundColor' => ['#f59e0b', '#38bdf8', '#a78bfa', '#f472b6'],
                    'borderColor' => ['#f59e0b', '#38bdf8', '#a78bfa', '#f472b6'],
                    'borderWidth' => 1,
                    'borderRadius' => 8,
                    'borderSkipped' => false,
                    'maxBarThickness' => 86,
                    'yAxisID' => 'weight',
                ],
                [
                    'type' => 'line',
                    'label' => 'Patients included (baseline + follow-up)',
                    'data' => array_map(
                        static fn (string $key): int => $performance[$key]['patients'],
                        $keys,
                    ),
                    'borderColor' => '#34d399',
                    'backgroundColor' => '#34d399',
                    'borderWidth' => 3,
                    'pointRadius' => 6,
                    'pointHoverRadius' => 8,
                    'tension' => 0.25,
                    'yAxisID' => 'patients',
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'layout' => [
                'padding' => ['top' => 22, 'right' => 14, 'bottom' => 12, 'left' => 10],
            ],
            'scales' => [
                'y' => ['display' => false],
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => ['font' => ['size' => 15, 'weight' => '600'], 'padding' => 12],
                ],
                'weight' => [
                    'beginAtZero' => true,
                    'position' => 'left',
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.10)'],
                    'ticks' => ['precision' => 1, 'font' => ['size' => 13], 'padding' => 10],
                    'title' => ['display' => true, 'text' => 'Average weight loss (kg)', 'font' => ['size' => 14, 'weight' => '600']],
                ],
                'patients' => [
                    'beginAtZero' => true,
                    'position' => 'right',
                    'grid' => ['drawOnChartArea' => false],
                    'ticks' => ['precision' => 0, 'stepSize' => 1, 'font' => ['size' => 13], 'padding' => 10],
                    'title' => ['display' => true, 'text' => 'Patients included', 'font' => ['size' => 14, 'weight' => '600']],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => [
                        'boxWidth' => 12,
                        'boxHeight' => 12,
                        'padding' => 24,
                        'usePointStyle' => true,
                        'pointStyle' => 'rectRounded',
                        'font' => ['size' => 14],
                    ],
                ],
                'tooltip' => ['padding' => 12],
            ],
        ];
    }
}
