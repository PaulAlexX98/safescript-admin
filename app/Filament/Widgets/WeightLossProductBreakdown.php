<?php

namespace App\Filament\Widgets;

use App\Support\WeightLossOverview;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class WeightLossProductBreakdown extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.weight-loss-product-breakdown';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public ?string $medication = null;

    public string $period = 'quarter';

    public function mount(): void
    {
        $this->medication = array_key_first(WeightLossOverview::productOptions());
    }

    public function getMedicationOptions(): array
    {
        return WeightLossOverview::productOptions();
    }

    public function getProducts()
    {
        return WeightLossOverview::productBreakdown(
            $this->period,
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
            $this->medication,
        );
    }
}
