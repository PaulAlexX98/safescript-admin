<?php

namespace App\Filament\Resources\NhsCompleteds;

use App\Filament\Resources\NhsCompleteds\Pages;
use App\Models\NhsPending;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class NhsCompletedResource extends Resource
{
    protected static ?string $model = NhsPending::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'NHS';
    protected static ?string $navigationLabel = 'NHS Completed';
    protected static ?int $navigationSort = 10;
    protected static ?string $pluralLabel = 'NHS Completed';
    protected static ?string $modelLabel = 'NHS Completed';

    // this is what you “put”
    protected static ?string $recordTitleAttribute = 'reference';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        // Completed marker stored on the pending row
        $q->where(function (Builder $w) {
            // status column if it exists
            if (\Schema::hasColumn('nhs_pendings', 'status')) {
                $w->orWhere('status', 'completed');
            }

            // meta flags (JSON)
            $w->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.nhs_pending_status'))) = 'completed'")
              ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.nhs_status'))) = 'completed'")
              ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.status'))) = 'completed'");

            // last-resort string search (handles TEXT meta / non-JSON content)
            $w->orWhereRaw('LOWER(meta) LIKE ?', ['%"nhs_pending_status":"completed"%'])
              ->orWhereRaw('LOWER(meta) LIKE ?', ['%"nhs_status":"completed"%'])
              ->orWhereRaw('LOWER(meta) LIKE ?', ['%"status":"completed"%']);
        });

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $first = data_get($meta, 'first_name') ?? data_get($meta, 'firstName') ?? data_get($meta, 'patient.first_name') ?? data_get($meta, 'patient.firstName');
                        $last  = data_get($meta, 'last_name')  ?? data_get($meta, 'lastName')  ?? data_get($meta, 'patient.last_name')  ?? data_get($meta, 'patient.lastName');
                        $name = trim(trim((string) $first) . ' ' . trim((string) $last));
                        if ($name !== '') return $name;

                        return data_get($meta, 'name')
                            ?? data_get($meta, 'patient.name')
                            ?? data_get($meta, 'user.name')
                            ?? '—';
                    })
                    ->wrap(),

                TextColumn::make('email')
                    ->label('Email')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        return data_get($meta, 'email')
                            ?? data_get($meta, 'patient.email')
                            ?? data_get($meta, 'user.email')
                            ?? $record->email
                            ?? '—';
                    })
                    ->searchable()
                    ->wrap(),

                TextColumn::make('service')
                    ->label('Service')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        return data_get($meta, 'service')
                            ?? data_get($meta, 'serviceName')
                            ?? data_get($meta, 'title')
                            ?? '—';
                    })
                    ->wrap(),

                TextColumn::make('product')
                    ->label('Product')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                        // Try common line item containers
                        $candidates = [
                            data_get($meta, 'items'),
                            data_get($meta, 'products'),
                            data_get($meta, 'lines'),
                            data_get($meta, 'line_items'),
                            data_get($meta, 'cart.items'),
                        ];

                        $items = null;
                        foreach ($candidates as $cand) {
                            if (is_string($cand)) {
                                $decoded = json_decode($cand, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $cand = $decoded;
                                }
                            }
                            if (is_array($cand) && count($cand)) {
                                $items = $cand;
                                break;
                            }
                        }

                        // If a single associative item was stored, wrap it
                        if (is_array($items)) {
                            $isList = array_keys($items) === range(0, count($items) - 1);
                            if (! $isList && (isset($items['name']) || isset($items['title']) || isset($items['product_name']))) {
                                $items = [$items];
                            }
                        }

                        $labels = [];
                        if (is_array($items)) {
                            foreach ($items as $it) {
                                if (is_string($it)) {
                                    $labels[] = '1 × ' . $it;
                                    continue;
                                }
                                if (! is_array($it)) {
                                    continue;
                                }

                                $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                                if ($qty < 1) $qty = 1;

                                $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? null;
                                if (! $name) {
                                    continue;
                                }

                                // Keep it simple: qty + name (no variation)
                                $labels[] = $qty . ' × ' . $name;

                                // Limit to first 2 lines in the table cell
                                if (count($labels) >= 2) {
                                    break;
                                }
                            }
                        }

                        if (empty($labels)) {
                            // Fallback to single-product scattered keys
                            $qty = (int) (data_get($meta, 'qty') ?? data_get($meta, 'quantity') ?? 1);
                            if ($qty < 1) $qty = 1;

                            $name = data_get($meta, 'product_name')
                                ?? data_get($meta, 'product')
                                ?? data_get($meta, 'selectedProduct.name')
                                ?? data_get($meta, 'selected_product.name')
                                ?? data_get($meta, 'medication.name')
                                ?? data_get($meta, 'drug.name');

                            if (is_string($name) && trim($name) !== '') {
                                $labels[] = $qty . ' × ' . trim($name);
                            }
                        }

                        if (! empty($labels)) {
                            $out = implode("\n", $labels);

                            // Show “+N more” when there are more than 2 items
                            if (is_array($items) && count($items) > 2) {
                                $out .= "\n+" . (count($items) - 2) . " more";
                            }

                            return $out;
                        }

                        return '—';
                    })
                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : null)
                    ->html()
                    ->wrap(),

                TextColumn::make('completed_state')
                    ->label('Status')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $s = data_get($meta, 'nhs_pending_status')
                            ?? data_get($meta, 'nhs_status')
                            ?? data_get($meta, 'status')
                            ?? ($record->status ?? null);
                        $s = strtolower(trim((string) $s));
                        return $s !== '' ? $s : 'completed';
                    })
                    ->badge()
                    ->color(fn ($state) => $state === 'completed' ? 'success' : 'gray'),
            ])
            ->recordUrl(null);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNhsCompleteds::route('/'),
        ];
    }
}