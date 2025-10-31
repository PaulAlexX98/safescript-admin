<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use App\Filament\Resources\Orders\Pages\ListUnpaidOrders;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class UnpaidOrderResource extends OrderResource
{
    protected static ?string $model = Order::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCurrencyPound;
    protected static ?string $navigationLabel  = 'Unpaid';
    protected static ?string $pluralLabel      = 'Unpaid';
    protected static ?string $modelLabel       = 'Unpaid';
    protected static string | \UnitEnum | null $navigationGroup = 'Orders';
    protected static ?int    $navigationSort   = 33;
    protected static bool    $shouldRegisterNavigation = true;

    // 🔑 This ensures route = /admin/unpaid-orders
    protected static ?string $slug = 'unpaid-orders';

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        // Filter only unpaid orders
        return parent::getEloquentQuery()->whereRaw('LOWER(payment_status) = ?', ['unpaid']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnpaidOrders::route('/'),
        ];
    }
}