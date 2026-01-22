<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use App\Filament\Resources\Orders\Pages\ListUnpaidOrders;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
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
        // Show normal unpaid orders immediately.
        // Show draft unpaid rows (created by RAF/Calendar/Payment) only after a delay.
        $minutes = (int) env('UNPAID_ABANDON_MINUTES', 15);
        if ($minutes < 0) $minutes = 0;

        // Treat JSON truthy values defensively (boolean true, 1, "1", "true")
        $draftTruthy = [true, 1, '1', 'true', 'TRUE'];
        $draftFalsy  = [false, 0, '0', 'false', 'FALSE', ''];

        return parent::getEloquentQuery()
            ->whereRaw('LOWER(payment_status) = ?', ['unpaid'])
            ->where(function (Builder $q) use ($minutes, $draftTruthy, $draftFalsy) {
                // Non-draft unpaid orders
                $q->whereNull('meta->draft')
                  ->orWhereIn('meta->draft', $draftFalsy)

                  // Draft orders
                  ->orWhere(function (Builder $qq) use ($minutes, $draftTruthy) {
                      $qq->whereIn('meta->draft', $draftTruthy);

                      // If minutes is 0, show drafts immediately (no cutoff).
                      if ($minutes > 0) {
                          $qq->where('updated_at', '<=', now()->subMinutes($minutes));
                      }
                  });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_type')
                    ->label('Type')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        $metaType = strtolower(trim((string) (
                            data_get($record, 'meta.type')
                            ?: data_get($record, 'meta.order_type')
                            ?: data_get($record, 'meta.mode')
                            ?: data_get($record, 'meta.flow')
                            ?: data_get($record, 'meta.path')
                        )));

                        $isReorder = in_array($metaType, ['reorder', 're-order', 'repeat', 'repeat_order', 'repeat-order'], true)
                            || (bool) data_get($record, 'meta.reorder')
                            || (bool) data_get($record, 'meta.is_reorder')
                            || (bool) data_get($record, 'meta.isReorder');

                        if ($isReorder) return 'Reorder';

                        $isNhs = str_contains($metaType, 'nhs')
                            || (bool) data_get($record, 'meta.nhs')
                            || (bool) data_get($record, 'meta.is_nhs')
                            || (bool) data_get($record, 'meta.isNhs');

                        if ($isNhs) return 'NHS';

                        return 'New';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Reorder' => 'warning',
                        'NHS' => 'info',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('meta.service')
                    ->label('Order Service')
                    ->getStateUsing(fn ($record) => (string) (
                        data_get($record, 'meta.service')
                        ?: data_get($record, 'meta.service_slug')
                        ?: data_get($record, 'meta.serviceSlug')
                        ?: ''
                    ))
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(function ($record) {
                        $first = trim((string) (
                            (is_string($record->first_name ?? null) ? $record->first_name : '')
                            ?: (string) data_get($record, 'meta.first_name')
                            ?: (string) data_get($record, 'meta.firstName')
                        ));
                        $last = trim((string) (
                            (is_string($record->last_name ?? null) ? $record->last_name : '')
                            ?: (string) data_get($record, 'meta.last_name')
                            ?: (string) data_get($record, 'meta.lastName')
                        ));
                        $name = trim($first . ' ' . $last);
                        if ($name !== '') return $name;
                        $metaName = trim((string) data_get($record, 'meta.customer.name'));
                        if ($metaName !== '') return $metaName;
                        return (string) ($record->user?->name ?? '');
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->getStateUsing(fn ($record) => (string) (
                        (is_string($record->email ?? null) ? $record->email : '')
                        ?: data_get($record, 'meta.email')
                        ?: data_get($record, 'meta.patient.email')
                        ?: ($record->user?->email ?? '')
                    ))
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Phone')
                    ->getStateUsing(fn ($record) => (string) (
                        (is_string($record->phone ?? null) ? $record->phone : '')
                        ?: data_get($record, 'meta.phone')
                        ?: data_get($record, 'meta.patient.phone')
                        ?: data_get($record, 'meta.patient.mobile')
                        ?: ($record->user?->phone ?? '')
                    ))
                    ->searchable(),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnpaidOrders::route('/'),
        ];
    }
}