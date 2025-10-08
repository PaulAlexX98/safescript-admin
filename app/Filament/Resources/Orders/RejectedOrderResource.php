<?php

namespace App\Filament\Resources\Orders;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class RejectedOrderResource extends OrderResource
{
    protected static ?string $slug = 'rejected-orders';
    protected static \UnitEnum|string|null $navigationGroup = 'Orders';
    protected static bool $shouldRegisterNavigation = true;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    protected static ?string $navigationLabel  = 'Rejected';
    protected static ?string $pluralLabel      = 'Rejected';
    protected static ?string $modelLabel       = 'Rejected';
    protected static ?int    $navigationSort   = 32;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedXCircle;
    protected static ?string $model = \App\Models\Order::class;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['user'])
            ->where(function (\Illuminate\Database\Eloquent\Builder $q) {
                $q->whereRaw('LOWER(status) = ?', ['rejected'])
                ->orWhereRaw('LOWER(booking_status) = ?', ['rejected']);
            })
            ->orderByDesc('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\Orders\Pages\ListRejectedOrders::route('/'),
        ];
    }
}