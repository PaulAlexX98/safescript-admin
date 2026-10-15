<?php

namespace App\Filament\Widgets;

use App\Support\WeightLossOverview;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class WeightLossTrend extends ChartWidget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.weight-loss-trend-chart';

    protected ?string $heading = 'Medication and new-patient trend';

    protected ?string $description = 'Paid medication units and new patients over time.';

    protected ?string $maxHeight = '440px';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    private ?array $trendData = null;

    protected function getFilters(): ?array
    {
        if (! empty($this->pageFilters['startDate']) || ! empty($this->pageFilters['endDate'])) {
            return null;
        }

        return [
            '3' => '3 months',
            '6' => '6 months',
            '12' => '12 months',
        ];
    }

    protected function getData(): array
    {
        $data = $this->getTrendData();

        $colours = [
            '#f59e0b', '#38bdf8', '#a78bfa', '#f472b6', '#fb7185',
            '#22d3ee', '#84cc16', '#eab308', '#818cf8', '#14b8a6',
        ];

        $datasets = [];
        foreach ($data['products'] as $index => $product) {
            $datasets[] = [
                'label' => $product['label'],
                'data' => $product['data'],
                'backgroundColor' => $colours[$index % count($colours)],
                'borderColor' => $colours[$index % count($colours)],
                'borderWidth' => 1,
                'borderRadius' => 6,
                'borderSkipped' => false,
                'maxBarThickness' => 42,
                'stack' => 'medication',
            ];
        }

        if (array_sum($data['new_patients']) > 0) {
            $datasets[] = [
                'type' => 'line',
                'label' => 'New patients',
                'data' => $data['new_patients'],
                'borderColor' => '#10b981',
                'backgroundColor' => '#10b981',
                'borderWidth' => 2,
                'pointRadius' => 3,
                'pointHoverRadius' => 5,
                'tension' => 0.3,
                'yAxisID' => 'patients',
            ];
        }

        return [
            'labels' => $data['labels'],
            'datasets' => $datasets,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        $showPatients = array_sum($this->getTrendData()['new_patients']) > 0;

        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'layout' => [
                'padding' => ['top' => 18, 'right' => 12, 'bottom' => 10, 'left' => 8],
            ],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => [
                    'stacked' => true,
                    'grid' => ['display' => false],
                    'ticks' => ['maxRotation' => 0, 'autoSkipPadding' => 16],
                ],
                'y' => [
                    'stacked' => true,
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.10)'],
                    'title' => ['display' => true, 'text' => 'Medication units'],
                ],
                'patients' => [
                    'display' => $showPatients,
                    'beginAtZero' => true,
                    'position' => 'right',
                    'grid' => ['drawOnChartArea' => false],
                    'ticks' => ['precision' => 0],
                    'title' => ['display' => true, 'text' => 'New patients'],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => [
                        'boxWidth' => 10,
                        'boxHeight' => 10,
                        'padding' => 24,
                        'usePointStyle' => true,
                        'pointStyle' => 'rectRounded',
                    ],
                ],
                'tooltip' => [
                    'padding' => 12,
                    'displayColors' => true,
                ],
            ],
        ];
    }

    private function getTrendData(): array
    {
        return $this->trendData ??= WeightLossOverview::trend(
            (int) ($this->filter ?? 6),
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );
    }
}
