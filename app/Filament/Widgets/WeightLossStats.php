<?php

namespace App\Filament\Widgets;

use App\Support\WeightLossOverview;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class WeightLossStats extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.weight-loss-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public string $period = 'month';

    public function getSummary(): array
    {
        return WeightLossOverview::summary(
            $this->period,
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );
    }
}
