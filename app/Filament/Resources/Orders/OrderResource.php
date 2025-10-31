<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Throwable;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Textarea;
use App\Filament\Resources\Orders\CompletedOrderResource as CompletedOrders;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Orders';
    protected static ?string $pluralLabel = 'Orders';
    protected static ?string $modelLabel = 'Order';

    // Hide base Orders resource from the sidebar; we use the status-specific resources instead.
    protected static bool $shouldRegisterNavigation = false;

    // Fallback for older Filament versions that don’t support the property.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(FilamentSchema $filamentSchema): FilamentSchema
    {
        return OrderForm::configure($filamentSchema);
    }

    public static function table(Table $table): Table
    {
        // Helpers reused by multiple columns
        $serviceFromMeta = function ($meta) {
            return (string) (
                data_get($meta, 'service')
                ?? data_get($meta, 'serviceName')
                ?? data_get($meta, 'treatment')
                ?? data_get($meta, 'title')
                ?? 'Weight Management Service'
            );
        };

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                // Order Service from meta
                TextColumn::make('meta.service')
                    ->label('Order Service')
                    ->getStateUsing(function ($record) use ($serviceFromMeta) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        return $serviceFromMeta($meta);
                    })
                    ->wrap()
                    ->toggleable(),

                // Items summary (multiple lines)
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->getStateUsing(function ($record) {
                        // Normalize collections / JSON strings / keyed containers into a plain array
                        $normalize = function ($value) {
                            if (is_string($value)) {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) $value = $decoded;
                            }
                            if ($value instanceof Collection) $value = $value->toArray();
                            if (is_array($value)) {
                                if (isset($value['items']) && is_array($value['items'])) return $value['items'];
                                if (isset($value['lines']) && is_array($value['lines'])) return $value['lines'];
                                if (isset($value['products']) && is_array($value['products'])) return $value['products'];
                                if (isset($value['data']) && is_array($value['data'])) return $value['data'];
                                // Wrap a single associative product into a list so foreach treats it as one line item
                                $isList = array_keys($value) === range(0, count($value) - 1);
                                if (
                                    !$isList &&
                                    (isset($value['name']) || isset($value['title']) || isset($value['product_name']))
                                ) {
                                    return [$value];
                                }
                            }
                            return is_array($value) ? $value : [];
                        };
                        // Turn a line item into "2 × Name Option", using structured fields (including nested keys & arrays)
                        $lineToString = function ($it) {
                            if (is_string($it)) return '1 × ' . $it;
                            if (!is_array($it)) return null;
                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                            if ($qty < 1) $qty = 1;
                            $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? null;
                            // Resolve variation (same strategy as PendingOrderResource)
                            $resolveOpt = function ($row) {
                                $keys = [
                                    'variations','variation','optionLabel','variant','dose','strength','option',
                                    'meta.variations','meta.variation','meta.optionLabel','meta.variant','meta.dose','meta.strength','meta.option',
                                    'selected.variations','selected.variation','selected.optionLabel','selected.variant','selected.dose','selected.strength','selected.option',
                                ];
                                foreach ($keys as $k) {
                                    $v = data_get($row, $k);
                                    if ($v !== null && $v !== '') {
                                        if (is_array($v)) {
                                            if (array_key_exists('label', $v)) return (string) $v['label'];
                                            if (array_key_exists('value', $v)) return (string) $v['value'];
                                            $flat = [];
                                            foreach ($v as $vv) {
                                                if (is_array($vv)) {
                                                    if (isset($vv['label'])) $flat[] = (string) $vv['label'];
                                                    elseif (isset($vv['value'])) $flat[] = (string) $vv['value'];
                                                } else {
                                                    $flat[] = (string) $vv;
                                                }
                                            }
                                            $j = trim(implode(' ', array_filter($flat, fn($s) => $s !== '')));
                                            if ($j !== '') return $j;
                                            continue;
                                        }
                                        return (string) $v;
                                    }
                                }
                                // options[] or attributes[] arrays
                                if (is_array($row['options'] ?? null)) {
                                    $optParts = [];
                                    foreach ($row['options'] as $op) {
                                        if (is_array($op)) $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                    }
                                    $opt = trim(implode(' ', array_filter(array_map('strval', array_filter($optParts)))));
                                    if ($opt !== '') return $opt;
                                }
                                if (is_array($row['attributes'] ?? null)) {
                                    $optParts = [];
                                    foreach ($row['attributes'] as $op) {
                                        if (is_array($op)) $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                    }
                                    $opt = trim(implode(' ', array_filter(array_map('strval', array_filter($optParts)))));
                                    if ($opt !== '') return $opt;
                                }
                                return null;
                            };
                            $opt = $resolveOpt($it);
                            $parts = [];
                            $parts[] = $qty . ' ×';
                            if ($name) $parts[] = $name;
                            if ($opt)  $parts[] = $opt;
                            return trim(implode(' ', $parts)) ?: null;
                        };
  
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $candidates = [
                            data_get($meta, 'items'),
                            data_get($meta, 'products'),
                            data_get($meta, 'order.items'),
                            data_get($meta, 'order.products'),
                            data_get($meta, 'order.lines'),
                            data_get($meta, 'order.line_items'),
                            data_get($meta, 'cart.items'),
                            data_get($meta, 'lines'),
                            data_get($meta, 'line_items'),
                        ];
                        $items = [];
                        foreach ($candidates as $cand) {
                            $arr = $normalize($cand);
                            if (!empty($arr)) { $items = $arr; break; }
                        }
  
                        $labels = [];
                        foreach ($items as $it) {
                            $label = $lineToString($it);
                            if ($label) $labels[] = $label;
                        }
  
                        // Single-product scattered keys fallback
                        if (empty($labels)) {
                            $qty  = (int) (data_get($meta, 'qty') ?? data_get($meta, 'quantity') ?? 1);
                            if ($qty < 1) $qty = 1;
                            $name = data_get($meta, 'product_name')
                                ?? data_get($meta, 'product')
                                ?? data_get($meta, 'selectedProduct.name')
                                ?? data_get($meta, 'selected_product.name')
                                ?? data_get($meta, 'medication.name')
                                ?? data_get($meta, 'drug.name')
                                ?? data_get($meta, 'product.name')
                                ?? data_get($meta, 'item.name')
                                ?? data_get($meta, 'line.name')
                                ?? data_get($meta, 'cart.item.name');
                            $opt  = data_get($meta, 'selectedProduct.variations')
                                ?? data_get($meta, 'selected_product.variations')
                                ?? data_get($meta, 'selectedProduct.optionLabel')
                                ?? data_get($meta, 'selected_product.optionLabel')
                                ?? data_get($meta, 'selectedProduct.variant')
                                ?? data_get($meta, 'selected_product.variant')
                                ?? data_get($meta, 'selectedProduct.dose')
                                ?? data_get($meta, 'selected_product.dose')
                                ?? data_get($meta, 'selectedProduct.strength')
                                ?? data_get($meta, 'selected_product.strength')
                                ?? data_get($meta, 'strength')
                                ?? data_get($meta, 'dose')
                                ?? data_get($meta, 'variant')
                                ?? data_get($meta, 'option');
                            if (is_array($opt)) {
                                $opt = $opt['label'] ?? $opt['value'] ?? trim(implode(' ', array_map('strval', $opt)));
                            }
                            if ($name) {
                                $labels[] = trim($qty . ' × ' . $name . ($opt ? ' ' . $opt : ''));
                            }
                        }
  
                        return !empty($labels) ? implode("\n", $labels) : null;
                    })
                    ->formatStateUsing(fn($state) => $state ? nl2br(e($state)) : null)
                    ->html()
                    ->wrap()
                    ->toggleable(),

                // Type (New / Transfer / Reorder)
                TextColumn::make('type')
                    ->label('Type')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $type = data_get($meta, 'type');
                        if (!is_string($type) || $type === '') {
                            $svc = strtolower((string) (data_get($meta, 'service') ?? data_get($meta, 'serviceName') ?? data_get($meta, 'title') ?? ''));
                            if ($svc !== '') {
                                if (str_contains($svc, 'nhs') || str_contains($svc, 'new')) $type = 'new';
                                elseif (str_contains($svc, 'reorder') || str_contains($svc, 'repeat') || str_contains($svc, 're-order')) $type = 'reorder';
                                elseif (str_contains($svc, 'transfer')) $type = 'transfer';
                                elseif (str_contains($svc, 'consult')) $type = 'consultation';
                            }
                        }
                        if (!is_string($type) || $type === '') return null;
                        return match (strtolower($type)) {
                            'new', 'nhs' => 'New Patient',
                            'transfer' => 'Transfer Patient',
                            'consultation' => 'Consultation',
                            'reorder', 'repeat', 're-order' => 'Reorder',
                            default => ucfirst($type),
                        };
                    })
                    ->toggleable(),

                // Name (from meta or user)
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $first = data_get($meta, 'firstName') ?? data_get($meta, 'first_name') ?? data_get($meta, 'patient.firstName') ?? data_get($meta, 'patient.first_name') ?? optional($record->user)->first_name;
                        $last  = data_get($meta, 'lastName')  ?? data_get($meta, 'last_name')  ?? data_get($meta, 'patient.lastName')  ?? data_get($meta, 'patient.last_name')  ?? optional($record->user)->last_name;
                        $name  = trim(($first ? $first : '') . ' ' . ($last ? $last : ''));
                        return $name !== '' ? $name : (optional($record->user)->name ?? null);
                    })
                    ->searchable()
                    ->toggleable(),

                // (Payment status and Order status columns removed)
            ])
            ->filters([
                // No filters by default for generic Orders resource
            ])
            ->recordActions([
                // For COMPLETED: go to the dedicated details page
                Action::make('viewDetails')
                    ->label('View')
                    ->color('warning')
                    ->button()
                    ->url(fn ($record) => CompletedOrders::getUrl('details', ['record' => $record]))
                    ->openUrlInNewTab(false)
                    ->visible(fn ($record) => strtolower((string)$record->status) === 'completed'),

                // For REJECTED / UNPAID: keep the existing modal with infolist
                Action::make('viewOrder')
                    ->label('View')
                    ->button()
                    ->color('primary')
                    ->modalHeading(fn ($record) => ($record->reference ? ('Order ' . $record->reference) : 'Order Details'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->visible(fn ($record) => in_array(strtolower((string)$record->status), ['rejected','unpaid'], true))
                    ->schema([
                        Grid::make(12)->schema([
                            Section::make('Customer')
                                ->columnSpan(8)
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextEntry::make('meta.firstName')
                                            ->label('First Name')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'firstName') ?? data_get($meta, 'first_name') ?? data_get($meta, 'patient.firstName') ?? data_get($meta, 'patient.first_name') ?? optional($record->user)->first_name;
                                            }),
                                        TextEntry::make('meta.lastName')
                                            ->label('Last Name')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'lastName') ?? data_get($meta, 'last_name') ?? data_get($meta, 'patient.lastName') ?? data_get($meta, 'patient.last_name') ?? optional($record->user)->last_name;
                                            }),
                                        TextEntry::make('meta.dob')
                                            ->label('DOB')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'dob') ?? optional($record->user)->dob;
                                            })
                                            ->formatStateUsing(function ($state) {
                                                if (empty($state)) return null;
                                                try { return Carbon::parse($state)->format('d-m-Y'); } catch (Throwable) { return (string)$state; }
                                            }),
                                        TextEntry::make('meta.email')
                                            ->label('Email')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'email') ?? optional($record->user)->email;
                                            }),
                                        TextEntry::make('meta.phone')
                                            ->label('Phone')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'phone') ?? optional($record->user)->phone;
                                            }),
                                        TextEntry::make('created_at')
                                            ->label('Created')
                                            ->dateTime('d-m-Y H:i'),
                                    ]),
                                ]),
                            Grid::make(1)
                                ->columnSpan(4)
                                ->schema([
                                    Section::make('Payment')
                                        ->schema([
                                            TextEntry::make('payment_status')
                                                ->hiddenLabel()
                                                ->badge()
                                                ->formatStateUsing(fn ($state) => $state ? ucfirst((string) $state) : null)
                                                ->color(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return match ($s) {
                                                        'paid'     => 'success',
                                                        'unpaid'   => 'warning',
                                                        'refunded' => 'danger',
                                                        default    => 'gray',
                                                    };
                                                }),
                                        ]),
                                    Section::make('Status')
                                        ->schema([
                                            TextEntry::make('status')
                                                ->hiddenLabel()
                                                ->badge()
                                                ->formatStateUsing(fn ($state) => $state ? ucfirst((string) $state) : null)
                                                ->color(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return match ($s) {
                                                        'completed' => 'success',
                                                        'rejected'  => 'danger',
                                                        'approved'  => 'primary',
                                                        'pending'   => 'warning',
                                                        'unpaid'    => 'warning',
                                                        default     => 'gray',
                                                    };
                                                }),
                                        ]),
                                ]),
                        ]),

                        // Rejection note (if any)
                        Section::make('Rejection Note')
                            ->visible(function ($record) {
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                return strtolower((string) $record->status) === 'rejected'
                                    || (string) (data_get($meta, 'rejection_notes') ?? '') !== '';
                            })
                            ->schema([
                                TextEntry::make('meta.rejection_notes')
                                    ->hiddenLabel()
                                    ->state(function ($record) {
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        return (string) (data_get($meta, 'rejection_notes') ?? '—');
                                    })
                                    ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                    ->html(),
                            ]),


                        // Items list remains as before
                        Section::make('Items')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                RepeatableEntry::make('meta.items')
                                    ->hiddenLabel()
                                    ->getStateUsing(function ($record) {
                                        $items = null;
                                        $candidates = [
                                            'meta.items', 'meta.lines', 'meta.products', 'meta.line_items', 'meta.cart.items',
                                        ];
                                        foreach ($candidates as $path) {
                                            $arr = data_get($record, $path);
                                            if (is_array($arr) && count($arr)) { $items = $arr; break; }
                                        }
                                        if (is_array($items)) {
                                            $isList = array_keys($items) === range(0, count($items) - 1);
                                            if (!$isList && (isset($items['name']) || isset($items['title']) || isset($items['product_name']))) {
                                                $items = [$items];
                                            }
                                        }
                                        if (!is_array($items) || empty($items)) return [];
                                        $out = [];
                                        foreach ($items as $it) {
                                            $name = $it['name'] ?? ($it['title'] ?? 'Item');
                                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                                            if ($qty < 1) $qty = 1;
                                            $var  = $it['variation'] ?? $it['variant'] ?? $it['strength'] ?? $it['dose'] ?? '';
                                            $out[] = [
                                                'name'      => (string) $name,
                                                'variation' => (string) $var,
                                                'qty'       => $qty,
                                            ];
                                        }
                                        return $out;
                                    })
                                    ->schema([
                                        Grid::make(12)->schema([
                                            TextEntry::make('name')->label('Product')->columnSpan(6),
                                            TextEntry::make('variation')->label('Variation')->formatStateUsing(fn ($state) => $state ?: '—')->columnSpan(3),
                                            TextEntry::make('qty')->label('Qty')->formatStateUsing(fn ($state) => (string) $state)->columnSpan(3),
                                        ]),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'edit'  => EditOrder::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Show all orders by default; use filters for Completed / Rejected / Paid / Unpaid views
        return parent::getEloquentQuery()
            ->with(['user'])
            ->orderByDesc('id');
    }

    // No navigation badge for generic Orders resource

    public static function canCreate(): bool
    {
        return false;
    }
}