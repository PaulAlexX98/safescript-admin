<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\WeightLossPerformance;
use App\Filament\Widgets\WeightLossProductBreakdown;
use App\Filament\Widgets\WeightLossStats;
use App\Filament\Widgets\WeightLossTrend;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WeightLossOverview extends Dashboard
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Weight Management';

    protected static ?string $navigationLabel = 'Weight Loss Overview';

    protected static ?string $title = 'Weight Loss Overview';

    protected static string $routePath = '/weight-loss-overview';

    protected static ?int $navigationSort = 1;

    protected ?string $subheading = 'A lightweight view of paid weight-management patients and medication sales.';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Custom date range')
                ->description('Choose both dates to apply the same period to every figure, chart, and table below. Leave blank to use the quick period selectors.')
                ->afterHeader([
                    Action::make('clearDateRange')
                        ->label('Clear dates')
                        ->icon('heroicon-o-x-mark')
                        ->color('gray')
                        ->action(fn () => $this->clearDateRange()),
                ])
                ->schema([
                    DatePicker::make('startDate')
                        ->label('From')
                        ->native(false)
                        ->maxDate(today()),
                    DatePicker::make('endDate')
                        ->label('To')
                        ->native(false)
                        ->afterOrEqual('startDate')
                        ->maxDate(today()),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public function clearDateRange(): void
    {
        $this->filters = [
            'startDate' => null,
            'endDate' => null,
        ];

        $this->getFiltersForm()->fill($this->filters);
        $this->updatedFilters();
    }

    public function getWidgets(): array
    {
        return [
            WeightLossStats::class,
            WeightLossPerformance::class,
            WeightLossTrend::class,
            WeightLossProductBreakdown::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
