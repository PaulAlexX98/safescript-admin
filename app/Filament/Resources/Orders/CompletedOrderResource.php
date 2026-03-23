<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use App\Filament\Resources\Orders\Pages\ListCompletedOrders;
use App\Filament\Resources\Orders\Pages\CompletedOrderDetails;
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
    protected static string | \UnitEnum | null $navigationGroup = 'Orders';
   
    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCheckCircle;
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $model = Order::class;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereRaw('LOWER(status) = ?', ['completed'])
            ->reorder()
            ->orderByRaw('COALESCE(completed_at, paid_at, approved_at, created_at) DESC')
            ->orderByDesc('id');
    }

    public static function getPages(): array
    {
        return [
            'index'   => ListCompletedOrders::route('/'),
            'details' => CompletedOrderDetails::route('/{record}/details'),
        ];
    }
}