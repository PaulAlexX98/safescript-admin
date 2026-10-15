<?php

namespace App\Filament\Widgets;

use App\Support\WeightLossOverview;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class WeightLossPerformance extends Widget
{
    use InteractsWithPageFilters;

    public string $period = 'six_months';

    protected string $view = 'filament.widgets.weight-loss-performance-chart';

    protected int|string|array $columnSpan = 'full';

    public function getPerformance(): array
    {
        return WeightLossOverview::treatmentPerformance(
            $this->period,
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );
    }
}
