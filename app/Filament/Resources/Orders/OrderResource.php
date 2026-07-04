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
use Illuminate\Support\Facades\DB;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Orders';
    protected static ?string $pluralLabel = 'Orders';
    protected static ?string $modelLabel = 'Order';

    protected static ?int    $navigationSort  = 6;

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
                TextColumn::make('completed_at')
    ->label(fn () => static::class === \App\Filament\Resources\Orders\RejectedOrderResource::class ? 'Rejected At' : 'Completed')
    ->getStateUsing(function ($record) {
        if (static::class === \App\Filament\Resources\Orders\RejectedOrderResource::class) {
            return $record->rejected_at ?? $record->updated_at ?? $record->created_at;
        }

        return $record->completed_at ?? $record->paid_at ?? $record->approved_at ?? $record->created_at;
    })
    ->dateTime('d M Y, H:i')
   ->sortable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $direction) {
    $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

    if (static::class === \App\Filament\Resources\Orders\RejectedOrderResource::class) {
        return $query->reorder()
            ->orderByRaw("COALESCE(rejected_at, updated_at, created_at) {$dir}")
            ->orderByDesc('id');
    }

    return $query->reorder()
        ->orderByRaw("COALESCE(completed_at, paid_at, approved_at, created_at) {$dir}")
        ->orderByDesc('id');
}),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                // Hidden, search-only JSON/related fields so the table search works without DB errors
                \Filament\Tables\Columns\TextColumn::make('meta->firstName')
                    ->label('First Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->lastName')
                    ->label('Last Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('user.first_name')
                    ->label('User First Name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('user.last_name')
                    ->label('User Last Name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->email')
                    ->label('Email (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->phone')
                    ->label('Phone (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Hidden, search-only product/item fields so search matches item names too
                \Filament\Tables\Columns\TextColumn::make('meta->product_name')
                    ->label('Product Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->product')
                    ->label('Product (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->selectedProduct->name')
                    ->label('Selected Product Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->selected_product->name')
                    ->label('Selected Product Name (Alt JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->selectedProduct->optionLabel')
                    ->label('Selected Option (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->selected_product->optionLabel')
                    ->label('Selected Option (Alt JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->medication->name')
                    ->label('Medication Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->drug->name')
                    ->label('Drug Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->item->name')
                    ->label('Item Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->line->name')
                    ->label('Line Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('meta->cart->item->name')
                    ->label('Cart Item Name (JSON)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // (Optional) This will search the entire items array JSON text – less efficient but broad
                \Filament\Tables\Columns\TextColumn::make('meta->items')
                    ->label('Items JSON')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                        $norm = fn ($v) => strtolower(trim((string) $v));

                        $raw  = $norm(data_get($meta, 'type'));
                        $mode = $norm(data_get($meta, 'mode') ?? data_get($meta, 'flow'));
                        $path = $norm(data_get($meta, 'path') ?? data_get($meta, 'source_url') ?? data_get($meta, 'referer'));
                        $svc  = $norm(data_get($meta, 'service') ?? data_get($meta, 'serviceName') ?? data_get($meta, 'title') ?? '');
                        $ref  = strtoupper((string) ($record->reference ?? ''));

                        $isReorder = in_array($raw, ['reorder','repeat','re-order','repeat-order'], true)
                            || in_array($mode, ['reorder','repeat'], true)
                            || str_contains($path, '/reorder')
                            || (bool) preg_match('/^PTC[A-Z]*R\d{6}$/', $ref)
                            || str_contains($svc, 'reorder') || str_contains($svc, 'repeat') || str_contains($svc, 're-order');

                        $isNhs = ($raw === 'nhs')
                            || (bool) preg_match('/^PTC[A-Z]*H\d{6}$/', $ref)
                            || str_contains($svc, 'nhs');

                        $isNew = ($raw === 'new')
                            || (bool) preg_match('/^PTC[A-Z]*N\d{6}$/', $ref);

                        if ($isReorder) return 'Reorder';
                        if ($isNhs)     return 'NHS';
                        if ($isNew)     return 'New';
                        return null;
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
                    ->toggleable(),

                // (Payment status and Order status columns removed)
            ])
            ->filters([
                // No filters by default for generic Orders resource
            ])
            ->headerActions([
                Action::make('sendRoyalMailDespatchEmails')
                    ->label('Send Royal Mail Despatch Emails')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Send Royal Mail despatch emails')
                    ->modalDescription('This will check Click & Drop and only email completed orders that have actually been manifested or shipped. Open orders will be skipped.')
                    ->modalSubmitActionLabel('Check and send')
                    ->action(function (): void {
                        $result = static::sendRoyalMailDespatchEmailsForManifestedOrders();

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Royal Mail despatch emails processed')
                            ->body("Sent: {$result['sent']}. Skipped not manifested: {$result['skipped_not_manifested']}. Skipped already sent: {$result['skipped_already_sent']}. Failed: {$result['failed']}.")
                            ->send();
                    }),
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

                // For REJECTED: keep the existing modal with infolist
                Action::make('viewOrder')
                    ->label('View')
                    ->button()
                    ->color('primary')
                    ->modalHeading(fn ($record) => ($record->reference ? ('Order ' . $record->reference) : 'Order Details'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->visible(fn ($record) => in_array(strtolower((string)$record->status), ['rejected'], true))
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

    protected static function sendRoyalMailDespatchEmailsForManifestedOrders(): array
    {
        $result = [
            'checked' => 0,
            'sent' => 0,
            'skipped_not_manifested' => 0,
            'skipped_already_sent' => 0,
            'failed' => 0,
        ];

        $clickAndDrop = app(\App\Services\Shipping\ClickAndDrop::class);

        $orders = Order::query()
            ->where('status', 'completed')
            ->latest('updated_at')
            ->limit(100)
            ->get();

        foreach ($orders as $order) {
            $meta = static::normaliseOrderMeta($order->meta);

            $clickAndDropOrderIdentifier = data_get($meta, 'clickanddrop.created_order_identifier')
                ?? data_get($meta, 'clickanddrop.order_identifier')
                ?? data_get($meta, 'clickanddrop.created_order.orderIdentifier')
                ?? data_get($meta, 'shipping.response.createdOrders.0.orderIdentifier');

            $trackingNumber = data_get($meta, 'shipping.tracking_number')
                ?? data_get($meta, 'shipping.tracking')
                ?? data_get($meta, 'royal_mail.tracking_number')
                ?? data_get($meta, 'clickanddrop.tracking_number')
                ?? data_get($meta, 'clickanddrop.created_order.trackingNumber')
                ?? data_get($meta, 'shipping.response.createdOrders.0.trackingNumber')
                ?? data_get($meta, 'shipping.response.createdOrders.0.packages.0.trackingNumber');

            if (! $clickAndDropOrderIdentifier || ! $trackingNumber) {
                continue;
            }

            $result['checked']++;

            if (data_get($meta, 'shipping.dispatch_email_sent_at')) {
                $result['skipped_already_sent']++;
                continue;
            }

            try {
                $clickAndDropOrder = $clickAndDrop->getOrder($clickAndDropOrderIdentifier);

                $isManifested = filled(data_get($clickAndDropOrder, 'manifestedOn'))
                    || filled(data_get($clickAndDropOrder, 'shippedOn'));

                if (! $isManifested) {
                    $result['skipped_not_manifested']++;
                    continue;
                }

                static::sendRoyalMailDespatchEmail($order, (string) $trackingNumber, $clickAndDropOrder);
                $result['sent']++;
            } catch (\Throwable $e) {
                $result['failed']++;

                \Log::warning('royalmail.despatch_email.failed', [
                    'order_id' => $order->getKey(),
                    'reference' => $order->reference,
                    'clickanddrop_order_identifier' => $clickAndDropOrderIdentifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        \Log::info('royalmail.despatch_email.batch_result', $result);

        return $result;
    }

    protected static function sendRoyalMailDespatchEmail(Order $order, string $trackingNumber, array $clickAndDropOrder = []): void
    {
        $meta = static::normaliseOrderMeta($order->meta);

        $email = data_get($meta, 'email')
            ?? data_get($meta, 'patient.email')
            ?? optional($order->patient)->email
            ?? optional($order->user)->email;

        if (! $email) {
            throw new \RuntimeException('No patient email found for order.');
        }

        $first = data_get($meta, 'firstName')
            ?? data_get($meta, 'first_name')
            ?? data_get($meta, 'patient.firstName')
            ?? data_get($meta, 'patient.first_name')
            ?? optional($order->patient)->first_name
            ?? optional($order->user)->first_name
            ?? '';

        $name = trim((string) $first) !== '' ? trim((string) $first) : 'there';
        $ref = $order->reference ?? $order->getKey();
        $trackingUrl = 'https://www.royalmail.com/track-your-item#/tracking-results/' . $trackingNumber;
        $subject = 'Your Pharmacy Express order has been despatched';

        $body = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($subject) . '</title>
</head>
<body style="margin:0;padding:0;background:#f6f6f4;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f6f4;margin:0;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid rgba(18,63,64,.14);">
                <tr>
                    <td style="background:#123f40;padding:34px 34px 30px 34px;border-bottom:4px solid #10c7a4;">
                        <p style="margin:0 0 14px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.20em;text-transform:uppercase;color:#10c7a4;font-weight:700;">Pharmacy Express</p>
                        <h1 style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:32px;line-height:38px;color:#ffffff;font-weight:800;">Order despatched</h1>
                        <p style="margin:14px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:rgba(255,255,255,.72);">Your order has now been despatched with Royal Mail.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:34px 34px 10px 34px;">
                        <p style="margin:0 0 18px 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;color:#111827;">Hi ' . e($name) . ',</p>
                        <p style="margin:0 0 14px 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;color:#111827;">Your Pharmacy Express order <strong style="color:#123f40;">' . e((string) $ref) . '</strong> has now been despatched with Royal Mail.</p>
                        <p style="margin:0 0 22px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#334155;">Tracking updates may take a little time to appear after Royal Mail scans the parcel.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 34px 26px 34px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef8f3;border:1px solid rgba(18,63,64,.14);">
                            <tr>
                                <td style="padding:22px 24px;">
                                    <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#123f40;font-weight:700;">Royal Mail tracking</p>
                                    <p style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:24px;line-height:30px;color:#123f40;font-weight:800;letter-spacing:.02em;">' . e($trackingNumber) . '</p>
                                    <a href="' . e($trackingUrl) . '" style="display:inline-block;background:#123f40;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;padding:12px 18px;">Track with Royal Mail</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 34px 32px 34px;">
                        <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:#64748b;">Thank you for ordering with Pharmacy Express.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>';

        \Illuminate\Support\Facades\Mail::html($body, function ($m) use ($email, $subject) {
            $m->to($email)->subject($subject);
        });

        data_set($meta, 'shipping.dispatch_email_sent_at', now()->toIso8601String());
        data_set($meta, 'shipping.dispatch_email_sent_by', auth()->id());
        data_set($meta, 'shipping.manifested_at', data_get($clickAndDropOrder, 'manifestedOn'));
        data_set($meta, 'shipping.shipped_at', data_get($clickAndDropOrder, 'shippedOn'));
        data_set($meta, 'shipping.tracking_number', $trackingNumber);
        data_set($meta, 'shipping.tracking_url', $trackingUrl);

        $order->meta = $meta;
        $order->save();

        \Log::info('royalmail.despatch_email.sent', [
            'order_id' => $order->getKey(),
            'reference' => $order->reference,
            'email' => $email,
            'tracking' => $trackingNumber,
        ]);
    }

    protected static function normaliseOrderMeta($meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
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
        // Exclude NHS orders from this resource (NHS has its own panel/resource)
        return parent::getEloquentQuery()
            ->with(['user'])
            ->whereRaw("reference NOT REGEXP '^PTC[A-Z]*H[0-9]{6}$'")
            ->whereRaw("(JSON_VALID(meta) = 0 OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) <> 'nhs')")
            ->orderByRaw('COALESCE(completed_at, paid_at, approved_at, created_at) DESC')
            ->orderByDesc('id');
    }

    // No navigation badge for generic Orders resource

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference'];
    }
}