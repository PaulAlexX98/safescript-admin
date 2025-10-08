<?php

namespace App\Filament\Resources\Orders;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompletedOrderResource extends OrderResource
{
    protected static ?string $navigationLabel  = 'Completed';
    protected static ?string $pluralLabel      = 'Completed';
    protected static ?string $modelLabel       = 'Completed';
    protected static \UnitEnum|string|null $navigationGroup = 'Orders';
    protected static ?int    $navigationSort   = 1;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $model = \App\Models\Order::class;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereRaw('LOWER(status) = ?', ['completed']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompletedOrders::route('/'),
        ];
    }
}