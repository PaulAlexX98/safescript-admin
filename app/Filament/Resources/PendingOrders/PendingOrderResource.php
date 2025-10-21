<?php

namespace App\Filament\Resources\PendingOrders;

use App\Filament\Resources\PendingOrders\Pages\CreatePendingOrder;
use App\Filament\Resources\PendingOrders\Pages\EditPendingOrder;
use App\Filament\Resources\PendingOrders\Pages\ListPendingOrders;
use App\Filament\Resources\PendingOrders\Schemas\PendingOrderForm;
use App\Filament\Resources\PendingOrders\Tables\PendingOrdersTable;
use Filament\Actions\Action;
use App\Models\PendingOrder;
use App\Models\Order;
use Illuminate\Support\Facades\Schema as DBSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Textarea;


class PendingOrderResource extends Resource
{
    protected static ?string $model = PendingOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Pending Approval';
    protected static ?string $pluralLabel = 'Pending Approval';
    protected static ?string $modelLabel = 'Pending Approval';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(FilamentSchema $schema): FilamentSchema
    {
        return PendingOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_datetime')
                    ->label('Order Date & Time')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $start = data_get($meta, 'appointment_start_at') ?: $record->created_at;
                        $end   = data_get($meta, 'appointment_end_at');

                        if (! $start) return null;

                        $fmt = function ($dt, $format = 'd-m-Y H:i') {
                            try { return \Carbon\Carbon::parse($dt)->format($format); } catch (\Throwable) { return (string) $dt; }
                        };

                        return $end ? ($fmt($start) . ' — ' . $fmt($end)) : $fmt($start);
                    })
                    ->sortable(query: function (Builder $query, string $direction) {
                        // Sort by meta->appointment_start_at (if present), otherwise created_at.
                        // We JSON_EXTRACT the ISO timestamp stored in meta and coalesce to created_at.
                        $dir = strtolower($direction) === 'asc' ? 'asc' : 'desc';
                        $query->orderByRaw(
                            "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.appointment_start_at')), created_at) {$dir}"
                        );
                    })
                    ->toggleable(),

                TextColumn::make('service_name')
                    ->label('Order Service')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $name = data_get($meta, 'service')
                            ?: data_get($meta, 'serviceName')
                            ?: data_get($meta, 'treatment')
                            ?: data_get($meta, 'title');
                        return $name ?: 'Weight Management Service';
                    })
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('order_item')
                    ->label('Order Item')
                    ->getStateUsing(function ($record) {
                        // Helper: normalize collections / JSON strings / keyed containers into a plain array
                        $normalize = function ($value) {
                            if (is_string($value)) {
                                $decoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $value = $decoded;
                                }
                            }
                            if ($value instanceof \Illuminate\Support\Collection) {
                                $value = $value->toArray();
                            }
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

                        // Helper: turn a line item into "2 × Name Option", using only structured fields, including nested keys and arrays
                        $lineToString = function ($it) {
                            if (is_string($it)) {
                                return '1 × ' . $it;
                            }
                            if (!is_array($it)) return null;
                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                            if ($qty < 1) $qty = 1;
                            $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? null;
                            // Resolve variation strictly from structured fields, allow nested keys and arrays
                            $resolveOpt = function ($row) {
                                $keys = [
                                    'variations', 'variation', 'optionLabel', 'variant', 'dose', 'strength', 'option',
                                    'meta.variations', 'meta.variation', 'meta.optionLabel', 'meta.variant', 'meta.dose', 'meta.strength', 'meta.option',
                                ];
                                foreach ($keys as $k) {
                                    $v = data_get($row, $k);
                                    if ($v !== null && $v !== '') {
                                        if (is_array($v)) {
                                            // If it's an associative array with label/value, prefer label
                                            if (array_key_exists('label', $v)) return (string) $v['label'];
                                            if (array_key_exists('value', $v)) return (string) $v['value'];
                                            // Otherwise join scalar parts with space
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
                                // options[] or attributes[]: join first or all labels
                                if (is_array($row['options'] ?? null)) {
                                    $optParts = [];
                                    foreach ($row['options'] as $op) {
                                        if (is_array($op)) {
                                            $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                        }
                                    }
                                    $opt = trim(implode(' ', array_filter(array_map('strval', array_filter($optParts)))));
                                    if ($opt !== '') return $opt;
                                }
                                if (is_array($row['attributes'] ?? null)) {
                                    $optParts = [];
                                    foreach ($row['attributes'] as $op) {
                                        if (is_array($op)) {
                                            $optParts[] = $op['label'] ?? $op['value'] ?? null;
                                        }
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

                        // Collect items from most common locations
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $candidates = [
                            data_get($meta, 'items'),
                            data_get($meta, 'products'),
                            data_get($meta, 'lines'),
                            data_get($meta, 'line_items'),
                            data_get($meta, 'cart.items'),
                            $record->products ?? null,
                            $record->items ?? null,
                            $record->lines ?? null,
                            $record->line_items ?? null
                        ];

                        $items = [];
                        foreach ($candidates as $cand) {
                            $arr = $normalize($cand);
                            if (!empty($arr)) {
                                $items = $arr;
                                break;
                            }
                        }

                        // Build labels for all items
                        $labels = [];
                        foreach ($items as $it) {
                            $label = $lineToString($it);
                            if ($label) $labels[] = $label;
                        }

                        // If nothing found, try single-product scattered keys
                        if (empty($labels)) {
                            $qty  = data_get($meta, 'qty')
                                ?? data_get($meta, 'quantity')
                                ?? data_get($meta, 'selectedProduct.qty')
                                ?? data_get($meta, 'selected_product.qty')
                                ?? data_get($meta, 'order.qty')
                                ?? data_get($meta, 'order.quantity')
                                ?? 1;

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
                                ?? data_get($meta, 'option')
                                ?? null;

                            if ($name) {
                                $labels[] = trim(((int)$qty ?: 1) . ' × ' . $name . ($opt ? ' ' . $opt : ''));
                            }
                        }

                        // Join all labels with line breaks so the table cell shows multiple lines
                        if (!empty($labels)) {
                            return implode("\n", $labels);
                        }

                        return null;
                    })
                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : null)
                    ->html()
                    ->wrap()
                    ->toggleable(),

                // Appointment Type (New / Transfer / Reorder)
                TextColumn::make('type')
                    ->label('Type')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $type = data_get($meta, 'type');

                        if (!is_string($type) || $type === '') {
                            $svc = (string) (data_get($meta, 'service')
                                ?? data_get($meta, 'serviceName')
                                ?? data_get($meta, 'title')
                                ?? '');
                            $t = strtolower($svc);
                            if ($t !== '') {
                                if (str_contains($t, 'nhs') || str_contains($t, 'new')) {
                                    $type = 'new';
                                } elseif (str_contains($t, 'reorder') || str_contains($t, 'repeat') || str_contains($t, 're-order')) {
                                    $type = 'reorder';
                                } elseif (str_contains($t, 'transfer')) {
                                    $type = 'transfer';
                                } elseif (str_contains($t, 'consult')) {
                                    $type = 'consultation';
                                }
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

                TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $first = null;
                        $last  = null;
                        foreach (['firstName', 'first_name', 'patient.firstName', 'patient.first_name'] as $k) {
                            $v = data_get($meta, $k);
                            if ($v) { $first = $v; break; }
                        }
                        foreach (['lastName', 'last_name', 'patient.lastName', 'patient.last_name'] as $k) {
                            $v = data_get($meta, $k);
                            if ($v) { $last = $v; break; }
                        }
                        if (!$first && isset($record->user)) { $first = $record->user->first_name ?? null; }
                        if (!$last && isset($record->user))  { $last  = $record->user->last_name  ?? null; }
                        $name = trim(trim((string)$first) . ' ' . trim((string)$last));
                        if ($name === '') {
                            // Fallback to a single name field if present
                            $name = $record->user->name ?? (data_get($meta, 'patient.name') ?? null);
                        }
                        return $name ?: '—';
                    })
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'new' => 'New Patient',
                        'transfer' => 'Transfer Patient',
                        'consultation' => 'Consultation',
                        'reorder' => 'Reorder',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $val = strtolower((string)($data['value'] ?? ''));
                        if ($val === '') {
                            return $query;
                        }

                        // Map UI values to the variants we actually see in data
                        $aliases = match ($val) {
                            'reorder' => ['reorder', 'repeat', 're-order'],
                            'new' => ['new', 'nhs'], // treat NHS JSON type as New Patient
                            default => [$val],
                        };

                        return $query->where(function (Builder $q) use ($aliases) {
                            // (1) JSON meta.type exact matches (support both $.type and $."type")
                            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
                            $q->where(function (Builder $qb) use ($aliases, $placeholders) {
                                $qb->whereRaw(
                                    "LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) IN ($placeholders)",
                                    $aliases
                                )->orWhereRaw(
                                    "LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"'))) IN ($placeholders)",
                                    $aliases
                                );
                            });

                            // (3) Fallback keyword inference from service/serviceName/title
                            foreach ($aliases as $a) {
                                $needle = "%$a%";

                                if (in_array($a, ['reorder','repeat','re-order'])) {
                                    $needle = '%reorder%';
                                } elseif ($a === 'consultation') {
                                    $needle = '%consult%';
                                }

                                if ($a === 'nhs' || $a === 'new') {
                                    // special-case: match either 'nhs' or 'new'
                                    $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", ['%nhs%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", ['%new%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", ['%nhs%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", ['%new%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", ['%nhs%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", ['%new%']);
                                } else {
                                    $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", [$needle])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", [$needle])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", [$needle]);
                                }
                            }
                        });
                    }),
            ])
            ->actions([
                Action::make('viewOrder')
                    ->label('View Order')
                    ->button()
                    ->color('primary')
                    ->modalHeading(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $name = data_get($meta, 'service')
                            ?: data_get($meta, 'serviceName')
                            ?: data_get($meta, 'treatment')
                            ?: data_get($meta, 'title')
                            ?: 'Weight Management Service';
                        return $name ?: 'Order Details';
                    })
                    ->modalDescription(function ($record) {
                        $ref = e($record->reference ?? '—');
                        return new \Illuminate\Support\HtmlString('<span class="text-xs text-gray-400">Order Ref: ' . $ref . '</span>');
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->infolist([
                        // Top two-column layout to "squeeze" content
                        Grid::make(12)->schema([
                            // LEFT COLUMN: Patient first
                            Section::make('Patient Details')
                                ->columnSpan(8)
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextEntry::make('patient_first_name')
                                            ->label('First Name')
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['firstName', 'first_name', 'patient.firstName', 'patient.first_name'] as $key) {
                                                    $v = data_get($meta, $key);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->first_name ?? null;
                                            }),
                                        TextEntry::make('patient_last_name')
                                            ->label('Last Name')
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['lastName', 'last_name', 'patient.lastName', 'patient.last_name'] as $key) {
                                                    $v = data_get($meta, $key);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->last_name ?? null;
                                            }),
                                        TextEntry::make('dob')
                                            ->label('DOB')
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['dob','dateOfBirth','patient.dob','patient.dateOfBirth'] as $k) {
                                                    $v = data_get($meta, $k);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->dob ?? null;
                                            })
                                            ->formatStateUsing(function ($state) {
                                                if (empty($state)) return null;
                                                try { return \Carbon\Carbon::parse($state)->format('d-m-Y'); } catch (\Throwable) { return $state; }
                                            }),
                                        TextEntry::make('meta.email')
                                            ->label('Email')
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'email') ?: ($record->user->email ?? null);
                                            }),
                                        TextEntry::make('meta.phone')
                                            ->label('Phone')
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'phone') ?: ($record->user->phone ?? null);
                                            }),
                                        TextEntry::make('created_at')
                                            ->label('Created')
                                            ->dateTime('d-m-Y H:i'),
                                    ]),
                                ]),
                            // RIGHT COLUMN: stack Payment Details over Appointment Date & Time
                            Grid::make(1)
                                ->columnSpan(4)
                                ->schema([
                                    Section::make('Payment Details')
                                        ->schema([
                                            TextEntry::make('payment_status')
                                                ->label('Payment')
                                                ->formatStateUsing(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return $s === 'paid' ? 'Paid' : ($s === 'unpaid' ? 'Unpaid' : ($s === 'refunded' ? 'Refunded' : ucfirst((string) $state)));
                                                })
                                                ->badge()
                                                ->color(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return match ($s) {
                                                        'paid' => 'success',
                                                        'unpaid' => 'warning',
                                                        'refunded' => 'danger',
                                                        default => 'gray',
                                                    };
                                                }),
                                        ]),
                                    Section::make('Appointment Date & Time')
                                        ->schema([
                                            TextEntry::make('order_datetime')
                                                ->hiddenLabel()
                                                ->getStateUsing(function ($record) {
                                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                    $type = strtolower((string) (data_get($meta, 'type', '')));
                                                    if (! in_array($type, ['consultation', 'new', 'transfer'], true)) {
                                                        return null;
                                                    }
                                                    $start = data_get($meta, 'appointment_start_at');
                                                    $end   = data_get($meta, 'appointment_end_at');
                                                    if (! $start) return null;
                                                    $fmt = function ($dt) {
                                                        try { return \Carbon\Carbon::parse($dt)->format('d-m-Y H:i'); } catch (\Throwable) { return (string) $dt; }
                                                    };
                                                    return $end ? ($fmt($start) . ' — ' . $fmt($end)) : $fmt($start);
                                                }),
                                        ]),
                                ]),
                        ]),
                        // Notes & Comments (patient notes above admin notes)
                        Section::make('Notes & Comments')
                            ->columnSpanFull()
                            ->schema([
                                Section::make('Patient Notes')
                                    ->schema([
                                        TextEntry::make('meta.patient_notes')
                                            ->hiddenLabel()
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'patient_notes') ?: 'No patient notes provided';
                                            })
                                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->html(),
                                    ]),
                                Section::make('Admin Notes')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('meta.admin_notes')
                                            ->hiddenLabel()
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return (string) (data_get($meta, 'admin_notes') ?: '—');
                                            })
                                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->extraAttributes(function ($record) {
                                                $ts = optional($record->updated_at)->timestamp ?? time();
                                                return ['wire:key' => 'pending-admin-notes-' . $record->getKey() . '-' . $ts];
                                            })
                                            ->html(),
                                    ]),
                            ]),

                        // Products (from order meta or booking relation)
                        Section::make(function ($record) {
                            // Build normalized products array to derive a dynamic title like: Products (N)
                            $items = [];
                            $candidates = [
                                'meta.items',
                                'meta.lines',
                                'meta.products',
                                'meta.line_items',
                                'meta.cart.items',
                            ];
                            foreach ($candidates as $path) {
                                $arr = data_get($record, $path);
                                if (is_array($arr) && count($arr)) { $items = $arr; break; }
                            }
                            $count = is_countable($items) ? count($items) : 0;
                            return $count ? 'Products (' . $count . ')' : 'Products';
                        })
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                RepeatableEntry::make('products')
                                    ->hiddenLabel()
                                    ->getStateUsing(function ($record) {
                                        // Normalize various meta shapes into a uniform array of { name, qty, priceMinor }
                                        $items = null;
                                        $candidates = [
                                            'meta.items',
                                            'meta.lines',
                                            'meta.products',
                                            'meta.line_items',
                                            'meta.cart.items',
                                        ];
                                        foreach ($candidates as $path) {
                                            $arr = data_get($record, $path);
                                            if (is_array($arr) && count($arr)) { $items = $arr; break; }
                                        }
                                        // If we have a single associative product object, wrap it so we treat it as one item
                                        if (is_array($items)) {
                                            $isList = array_keys($items) === range(0, count($items) - 1);
                                            if (
                                                !$isList &&
                                                (isset($items['name']) || isset($items['title']) || isset($items['product_name']))
                                            ) {
                                                $items = [$items];
                                            }
                                        }
                                        // Cache item count for downstream fallbacks
                                        $__itemsCount = is_array($items) ? count($items) : 0;
                                        if (!is_array($items) || empty($items)) return [];

                                        $out = [];
                                        foreach ($items as $it) {
                                            if (is_string($it)) {
                                                $out[] = [
                                                    'name'       => (string) $it,
                                                    'variation'  => '',
                                                    'qty'        => 1,
                                                    'priceMinor' => null,
                                                    'price'      => null,
                                                ];
                                                continue;
                                            }
                                            if (!is_array($it)) continue;

                                            $name = $it['name'] ?? ($it['title'] ?? 'Item');
                                            $qty = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                                            if ($qty < 1) $qty = 1;

                                            // Enhanced variation resolution, supports nested keys and arrays
                                            $resolveVar = function ($row) {
                                                $keys = [
                                                    'variations', 'variation', 'optionLabel', 'variant', 'dose', 'strength', 'option',
                                                    'meta.variations', 'meta.variation', 'meta.optionLabel', 'meta.variant', 'meta.dose', 'meta.strength', 'meta.option',
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
                                                if (is_array($row['options'] ?? null)) {
                                                    $parts = [];
                                                    foreach ($row['options'] as $op) {
                                                        if (is_array($op)) $parts[] = $op['label'] ?? $op['value'] ?? null;
                                                    }
                                                    $j = trim(implode(' ', array_filter(array_map('strval', array_filter($parts)))));
                                                    if ($j !== '') return $j;
                                                }
                                                if (is_array($row['attributes'] ?? null)) {
                                                    $parts = [];
                                                    foreach ($row['attributes'] as $op) {
                                                        if (is_array($op)) $parts[] = $op['label'] ?? $op['value'] ?? null;
                                                    }
                                                    $j = trim(implode(' ', array_filter(array_map('strval', array_filter($parts)))));
                                                    if ($j !== '') return $j;
                                                }
                                                return '';
                                            };
                                            $variation = $resolveVar($it);
                                            if (($variation === '' || $variation === null) && $__itemsCount === 1) {
                                                $metaRoot = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                $variation = data_get($metaRoot, 'selectedProduct.variations')
                                                    ?? data_get($metaRoot, 'selected_product.variations')
                                                    ?? data_get($metaRoot, 'selectedProduct.variation')
                                                    ?? data_get($metaRoot, 'selected_product.variation')
                                                    ?? data_get($metaRoot, 'selectedProduct.optionLabel')
                                                    ?? data_get($metaRoot, 'selected_product.optionLabel')
                                                    ?? data_get($metaRoot, 'selectedProduct.variant')
                                                    ?? data_get($metaRoot, 'selected_product.variant')
                                                    ?? data_get($metaRoot, 'selectedProduct.dose')
                                                    ?? data_get($metaRoot, 'selected_product.dose')
                                                    ?? data_get($metaRoot, 'selectedProduct.strength')
                                                    ?? data_get($metaRoot, 'selected_product.strength')
                                                    ?? data_get($metaRoot, 'variant')
                                                    ?? data_get($metaRoot, 'dose')
                                                    ?? data_get($metaRoot, 'strength')
                                                    ?? data_get($metaRoot, 'variation')
                                                    ?? data_get($metaRoot, 'option')
                                                    ?? '';
                                                if (is_array($variation)) {
                                                    // normalize array/assoc to string
                                                    $variation = is_string($variation['label'] ?? null) ? $variation['label']
                                                        : (is_string($variation['value'] ?? null) ? $variation['value'] : trim(implode(' ', array_map('strval', $variation))));
                                                }
                                            }

                                            // Helper to parse a money string like "£24.99", "24.99", "24", "24,99" into minor units
                                            $parseMoneyToMinor = function ($value) {
                                                if ($value === null || $value === '') return null;
                                                if (is_int($value)) return $value; // assume already in minor
                                                if (is_float($value)) return (int) round($value * 100);
                                                if (is_string($value)) {
                                                    $s = trim($value);
                                                    // remove currency symbols and spaces
                                                    $s = preg_replace('/[^\d\.\,\-]/', '', $s);
                                                    // convert comma decimal to dot if needed
                                                    if (strpos($s, ',') !== false && strpos($s, '.') === false) {
                                                        $s = str_replace(',', '.', $s);
                                                    } else {
                                                        $s = str_replace(',', '', $s);
                                                    }
                                                    if ($s === '' || !is_numeric($s)) return null;
                                                    return (int) round(((float) $s) * 100);
                                                }
                                                return null;
                                            };

                                            // Try common minor-unit keys first
                                            $minorCandidates = [
                                                // common line totals
                                                'lineTotalMinor', 'line_total_minor', 'line_total_pennies', 'lineTotalPennies',
                                                // generic totals
                                                'totalMinor', 'total_minor', 'totalPennies', 'total_pennies',
                                                'amountMinor', 'amount_minor',
                                                'subtotalMinor', 'subtotal_minor',
                                                // explicit price minor keys
                                                'priceMinor', 'price_minor', 'priceInMinor', 'priceInPence', 'price_in_minor', 'price_in_pence',
                                                // unit minors that need multiplying by qty
                                                'unitMinor', 'unit_minor', 'unitPriceMinor', 'unit_price_minor', 'unitPricePennies', 'unit_price_pennies',
                                                // other variations we have seen
                                                'minor', 'pennies', 'value_minor', 'valueMinor',
                                            ];
                                            $unitMinor = null;
                                            $priceMinor = null;
                                            foreach ($minorCandidates as $key) {
                                                if (array_key_exists($key, $it) && $it[$key] !== null && $it[$key] !== '') {
                                                    $val = $it[$key];
                                                    // unit* keys are per-unit prices
                                                    if (in_array($key, ['unitMinor','unit_minor','unitPriceMinor','unit_price_minor'], true)) {
                                                        $unitMinor = (int) $val;
                                                    } else {
                                                        $priceMinor = (int) $val;
                                                        break;
                                                    }
                                                }
                                            }
                                            if ($priceMinor === null && $unitMinor !== null) {
                                                $priceMinor = (int) $unitMinor * $qty;
                                            }

                                            // If still null, try major-unit keys and strings
                                            if ($priceMinor === null) {
                                                $majorCandidates = [
                                                    // line totals first
                                                    'lineTotal', 'line_total', 'linePrice', 'line_price', 'line_total_price',
                                                    // generic totals
                                                    'total', 'amount', 'subtotal',
                                                    // price values
                                                    'price', 'cost',
                                                    // unit prices that must be multiplied
                                                    'unitPrice', 'unit_price', 'unitCost', 'unit_cost',
                                                ];
                                                $unitMajor = null;
                                                foreach ($majorCandidates as $key) {
                                                    if (array_key_exists($key, $it) && $it[$key] !== null && $it[$key] !== '') {
                                                        if (in_array($key, ['unitPrice','unit_price','unitCost','unit_cost'], true)) {
                                                            $unitMajor = $parseMoneyToMinor($it[$key]);
                                                        } else {
                                                            $parsed = $parseMoneyToMinor($it[$key]);
                                                            if ($parsed !== null) { $priceMinor = $parsed; break; }
                                                        }
                                                    }
                                                }
                                                if ($priceMinor === null && $unitMajor !== null) {
                                                    $priceMinor = (int) $unitMajor * $qty;
                                                }
                                            }

                                            // Final fallback: look for nested money fields
                                            if ($priceMinor === null) {
                                                $maybe = data_get($it, 'money.totalMinor')
                                                     ?? data_get($it, 'money.amountMinor')
                                                     ?? data_get($it, 'money.subtotalMinor')
                                                     ?? data_get($it, 'money.lineTotalMinor')
                                                     ?? data_get($it, 'pricing.totalMinor')
                                                     ?? data_get($it, 'pricing.amountMinor')
                                                     ?? data_get($it, 'pricing.subtotalMinor')
                                                     ?? data_get($it, 'price.minor')
                                                     ?? data_get($it, 'total.minor')
                                                     ?? data_get($it, 'subtotal.minor')
                                                     ?? data_get($it, 'price.pennies')
                                                     ?? data_get($it, 'total.pennies')
                                                     ?? data_get($it, 'subtotal.pennies');
                                                if ($priceMinor === null && is_numeric($maybe)) {
                                                    $priceMinor = (int) $maybe;
                                                }
                                                // If only a major nested value exists, parse and multiply if unit price
                                                if ($priceMinor === null) {
                                                    $maybeMajor = data_get($it, 'money.total')
                                                               ?? data_get($it, 'pricing.total')
                                                               ?? data_get($it, 'price.value')
                                                               ?? data_get($it, 'total.value')
                                                               ?? data_get($it, 'subtotal.value');
                                                    $unitMaybeMajor = data_get($it, 'money.unit')
                                                                  ?? data_get($it, 'pricing.unit')
                                                                  ?? data_get($it, 'price.unit');
                                                    $parsed = $parseMoneyToMinor($maybeMajor);
                                                    if ($parsed !== null) $priceMinor = $parsed;
                                                    if ($priceMinor === null) {
                                                        $unitParsed = $parseMoneyToMinor($unitMaybeMajor);
                                                        if ($unitParsed !== null) $priceMinor = (int) $unitParsed * $qty;
                                                    }
                                                }
                                            }

                                            // Allow string numeric values for totalMinor/unitMinor
                                            if ($priceMinor === null && array_key_exists('totalMinor', $it) && $it['totalMinor'] !== null && $it['totalMinor'] !== '') {
                                                $priceMinor = (int) $it['totalMinor'];
                                            }

                                            // As a very last resort, if we still don't have a per-line price, but the item has an 'amountMinor'
                                            // or 'amount' key that looks like the per-line price, try those:
                                            if ($priceMinor === null) {
                                                if (array_key_exists('amountMinor', $it) && $it['amountMinor'] !== null && $it['amountMinor'] !== '') {
                                                    $priceMinor = (int) $it['amountMinor'] * $qty;
                                                } elseif (array_key_exists('amount', $it) && $it['amount'] !== null && $it['amount'] !== '') {
                                                    $maybe = $parseMoneyToMinor($it['amount']);
                                                    if ($maybe !== null) $priceMinor = (int) $maybe * $qty;
                                                }
                                            }

                                            if ($priceMinor === null && $unitMinor !== null) {
                                                $priceMinor = (int) $unitMinor * $qty;
                                            }

                                            // FINAL SINGLE-ITEM FALLBACK: if no per-line price could be derived, but the order has a total and there is exactly one item, use the order total
                                            if ($priceMinor === null && $__itemsCount === 1) {
                                                $orderTotalMinor = data_get($record, 'meta.totalMinor')
                                                    ?? data_get($record, 'meta.amountMinor')
                                                    ?? data_get($record, 'meta.total_minor')
                                                    ?? data_get($record, 'meta.amount_minor');
                                                if (is_numeric($orderTotalMinor)) {
                                                    $priceMinor = (int) $orderTotalMinor;
                                                }
                                            }

                                            $displayPrice = (is_numeric($priceMinor) ? ('£' . number_format(((int) $priceMinor) / 100, 2)) : '—');

                                            $out[] = [
                                                'name'           => (string) $name,
                                                'variation'      => (string) $variation,
                                                'qty'            => $qty,
                                                'priceMinor'     => $priceMinor,
                                                'price'          => $priceMinor, // alias retained for compatibility
                                                'priceFormatted' => $displayPrice,
                                            ];
                                        }
                                        return $out;
                                    })
                                    ->schema([
                                        Grid::make(12)->schema([
                                            TextEntry::make('name')
                                                ->label('Product')
                                                ->columnSpan(6),

                                            TextEntry::make('variation')
                                                ->label('Variation')
                                                ->formatStateUsing(fn ($state) => $state ?: '—')
                                                ->columnSpan(3),

                                            TextEntry::make('qty')
                                                ->label('Qty')
                                                ->formatStateUsing(fn ($state) => (string) $state)
                                                ->columnSpan(1),

                                            TextEntry::make('priceFormatted')
                                                ->label('Price')
                                                ->columnSpan(2)
                                                ->formatStateUsing(fn ($state) => (string) $state)
                                                ->extraAttributes(['class' => 'text-right whitespace-nowrap']),
                                        ]),
                                    ])
                                    ->columnSpanFull(),
                                Grid::make(12)
                                    ->columnSpanFull()
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('products_total_label')
                                            ->hiddenLabel()
                                            ->default('Total')
                                            ->columnSpan(10)
                                            ->extraAttributes(['class' => 'text-right font-medium']),
                                        \Filament\Infolists\Components\TextEntry::make('products_total_minor')
                                            ->label('') // no header over value cell
                                            ->hiddenLabel()
                                            ->columnSpan(2)
                                            ->getStateUsing(fn ($record) => $record->products_total_minor)
                                            ->formatStateUsing(fn ($state) => '£' . number_format(((int) $state) / 100, 2))
                                            ->placeholder('£0.00')
                                            ->extraAttributes(['class' => 'text-right tabular-nums']),
                                    ]),
                            ]),

                        // Assessment / RAF Form Answers
                        Section::make('Assessment Answers')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                // Prefer a Blade view renderer if present; it receives $state = answers array
                                ViewEntry::make('assessment_answers')
                                    ->label(false)
                                    ->getStateUsing(function ($record) {
                                        // --- compute raw answers array (no formatting) ---
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                        // 1) Prefer the real shape: assessment.answers
                                        $answers = data_get($meta, 'assessment.answers');

                                        // 2) If assessment exists but answers are nested or it's already flat, normalize
                                        if (empty($answers)) {
                                            $ass = data_get($meta, 'assessment');
                                            if (is_array($ass)) {
                                                $answers = array_key_exists('answers', $ass) && is_array($ass['answers'])
                                                    ? $ass['answers']
                                                    : $ass;
                                            }
                                        }

                                        // 3) Other legacy keys the FE may have used
                                        if (empty($answers)) {
                                            $answers = data_get($meta, 'formAnswers')
                                                ?? data_get($meta, 'form_answers')
                                                ?? data_get($meta, 'answers');
                                        }

                                        // 4) If still empty, try consultation_session meta (by explicit id, then latest by user)
                                        if (empty($answers)) {
                                            $sessionId = data_get($meta, 'consultation_session_id')
                                                ?? data_get($meta, 'session_id')
                                                ?? data_get($meta, 'intake.session_id');

                                            $sessionMeta = null;
                                            if ($sessionId) {
                                                try {
                                                    $sessionMeta = \Illuminate\Support\Facades\DB::table('consultation_sessions')
                                                        ->where('id', (int) $sessionId)
                                                        ->value('meta');
                                                } catch (\Throwable $e) { $sessionMeta = null; }
                                            }
                                            if ($sessionMeta === null && !empty($record->user_id)) {
                                                try {
                                                    $sessionMeta = \Illuminate\Support\Facades\DB::table('consultation_sessions')
                                                        ->where('user_id', (int) $record->user_id)
                                                        ->orderByDesc('id')
                                                        ->value('meta');
                                                } catch (\Throwable $e) { $sessionMeta = null; }
                                            }
                                            if ($sessionMeta) {
                                                $sessionMetaArr = is_array($sessionMeta) ? $sessionMeta : (json_decode($sessionMeta, true) ?: []);
                                                // Prefer session.assessment.answers, then answers
                                                $answers = data_get($sessionMetaArr, 'assessment.answers')
                                                    ?? (is_array(data_get($sessionMetaArr, 'assessment')) && is_array(data_get($sessionMetaArr, 'assessment.answers', null))
                                                        ? data_get($sessionMetaArr, 'assessment.answers')
                                                        : (data_get($sessionMetaArr, 'answers') ?? $sessionMetaArr));
                                            }
                                        }

                                        // Ensure flat array (in case something passed a wrapper again)
                                        if (is_array($answers) && array_key_exists('answers', $answers) && is_array($answers['answers'])) {
                                            $answers = $answers['answers'];
                                        }

                                        return (is_array($answers) ? $answers : []);
                                    })
                                    ->view('filament/pending-orders/assessment-card') // Blade view you can customize
                                    ->columnSpanFull()
                                    ->hidden(function () {
                                        return ! \Illuminate\Support\Facades\View::exists('filament/pending-orders/assessment-card');
                                    }),

                                // Fallback renderer (shown only if the Blade view does not exist yet)
                                \Filament\Infolists\Components\TextEntry::make('assessment_render_fallback')
                                    ->hidden(function () {
                                        return \Illuminate\Support\Facades\View::exists('filament/pending-orders/assessment-card');
                                    })
                                    ->hiddenLabel()
                                    ->getStateUsing(function ($record) {
                                        // 1) Resolve answers using the same normalized logic as the view above
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $answers = data_get($meta, 'assessment.answers');

                                        if (empty($answers)) {
                                            $ass = data_get($meta, 'assessment');
                                            if (is_array($ass)) {
                                                $answers = array_key_exists('answers', $ass) && is_array($ass['answers'])
                                                    ? $ass['answers']
                                                    : $ass;
                                            }
                                        }
                                        if (empty($answers)) {
                                            $answers = data_get($meta, 'formAnswers')
                                                ?? data_get($meta, 'form_answers')
                                                ?? data_get($meta, 'answers');
                                        }
                                        if (empty($answers)) {
                                            $sessionId = data_get($meta, 'consultation_session_id')
                                                ?? data_get($meta, 'session_id')
                                                ?? data_get($meta, 'intake.session_id');

                                            $sessionMeta = null;
                                            if ($sessionId) {
                                                try {
                                                    $sessionMeta = \Illuminate\Support\Facades\DB::table('consultation_sessions')
                                                        ->where('id', (int) $sessionId)
                                                        ->value('meta');
                                                } catch (\Throwable $e) { $sessionMeta = null; }
                                            }
                                            if ($sessionMeta === null && !empty($record->user_id)) {
                                                try {
                                                    $sessionMeta = \Illuminate\Support\Facades\DB::table('consultation_sessions')
                                                        ->where('user_id', (int) $record->user_id)
                                                        ->orderByDesc('id')
                                                        ->value('meta');
                                                } catch (\Throwable $e) { $sessionMeta = null; }
                                            }
                                            if ($sessionMeta) {
                                                $sessionMetaArr = is_array($sessionMeta) ? $sessionMeta : (json_decode($sessionMeta, true) ?: []);
                                                $answers = data_get($sessionMetaArr, 'assessment.answers')
                                                    ?? (is_array(data_get($sessionMetaArr, 'assessment')) && is_array(data_get($sessionMetaArr, 'assessment.answers', null))
                                                        ? data_get($sessionMetaArr, 'assessment.answers')
                                                        : (data_get($sessionMetaArr, 'answers') ?? $sessionMetaArr));
                                            }
                                        }
                                        if (is_array($answers) && array_key_exists('answers', $answers) && is_array($answers['answers'])) {
                                            $answers = $answers['answers'];
                                        }

                                        if (empty($answers) || !is_array($answers)) {
                                            return '&lt;em&gt;No answers submitted&lt;/em&gt;';
                                        }

                                        // simple flat fallback while Blade view is missing
                                        $fmt = function ($v) {
                                            if (is_bool($v)) return $v ? 'Yes' : 'No';
                                            if (is_array($v)) return implode(', ', array_map(fn($x) => is_scalar($x)? (string)$x : json_encode($x), $v));
                                            if ($v === null || $v === '') return '—';
                                            return (string) $v;
                                        };
                                        $rows = [];
                                        foreach ($answers as $k => $v) {
                                            $label = ucwords(str_replace(['_','-'],' ', (string) $k));
                                            $rows[] = '&lt;tr&gt;&lt;th class="text-left pr-3 align-top whitespace-nowrap"&gt;'
                                                . e($label) . ':&lt;/th&gt;&lt;td class="align-top"&gt;'
                                                . e($fmt($v))
                                                . '&lt;/td&gt;&lt;/tr&gt;';
                                        }
                                        return '&lt;div class="rounded border border-gray-200 p-3"&gt;&lt;table class="text-sm w-full"&gt;' . implode('', $rows) . '&lt;/table&gt;&lt;/div&gt;';
                                    })
                                    ->html()
                                    ->columnSpanFull(),
                            ])
                    ])
                    ->extraModalFooterActions([
                        Action::make('approve')
                            ->label('Approve')
                            ->color('success')
                            ->icon('heroicon-o-check')
                            ->action(function (PendingOrder $record, Action $action) {
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                data_set($meta, 'approved_at', now()->toISOString());
                                $record->status = 'approved';
                                $record->meta = $meta;
                                $record->booking_status = 'approved';
                                $record->save();
                                // Also sync the real Order by reference so the frontend reflects the change
                                try {
                                    $order = Order::where('reference', $record->reference)->first();
                                    if ($order) {
                                        $orderMeta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                        // carry approved_at into order meta as well
                                        data_set($orderMeta, 'approved_at', now()->toISOString());

                                        $order->forceFill([
                                            'status'         => 'approved',
                                            'booking_status' => 'approved',
                                            'approved_at'    => now(),
                                            'meta'           => $orderMeta,
                                        ])->save();
                                    }
                                } catch (\Throwable $e) {
                                    // swallow to avoid breaking the UI; consider logging if needed
                                }
                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                                return redirect(\App\Filament\Resources\PendingOrders\Pages\ListPendingOrders::getUrl());
                            }),
                        Action::make('reject')
                            ->label('Reject')
                            ->color('danger')
                            ->icon('heroicon-o-x-mark')
                            ->modalHeading('Reject Order')
                            ->modalDescription('Please provide a reason. This note will be saved with the order.')
                            ->modalSubmitActionLabel('Reject')
                            ->requiresConfirmation(false)
                            ->form([
                                \Filament\Forms\Components\Textarea::make('rejection_note')
                                    ->label('Rejection Note')
                                    ->rows(4)
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->action(function (PendingOrder $record, array $data, Action $action) {
                                $note = trim((string)($data['rejection_note'] ?? ''));
                                $timestamp = now()->format('d-m-Y H:i');
                                $noteLine = $timestamp . ': ' . ($note !== '' ? $note : 'Rejected');

                                // Load & update meta with note + timestamp (de-duplicate identical lines)
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $existing = (string) (data_get($meta, 'rejection_notes', '') ?? '');
                                $lines = preg_split("/\r\n|\n|\r/", $existing, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                                if (!in_array($noteLine, $lines, true)) {
                                    $lines[] = $noteLine;
                                }
                                data_set($meta, 'rejection_notes', implode("\n", $lines));
                                data_set($meta, 'rejected_at', now()->toISOString());
                                $record->meta = $meta;

                                // Ensure the order leaves Pending view and shows in Rejected
                                $record->status = 'rejected';
                                $record->booking_status = 'rejected';

                                // Flip payment to refunded if it had been paid
                                $currentPayment = strtolower((string) $record->payment_status);
                                if ($currentPayment === 'paid') {
                                    $record->payment_status = 'refunded';
                                    // If you later add a refunded_at column, uncomment:
                                    // $record->refunded_at = now();
                                }

                                $record->save();

                                // Also sync the real Order by reference so the frontend reflects the change
                                try {
                                    $order = Order::where('reference', $record->reference)->first();
                                    if ($order) {
                                        $orderMeta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

                                        // Append the same rejection note and timestamps into the Order meta (de-duplicate identical lines)
                                        $existingOrderNotes = (string) (data_get($orderMeta, 'rejection_notes', '') ?? '');
                                        $orderLines = preg_split("/\r\n|\n|\r/", $existingOrderNotes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                                        $orderNoteLine = $noteLine; // same line as in PendingOrder meta
                                        if (!in_array($orderNoteLine, $orderLines, true)) {
                                            $orderLines[] = $orderNoteLine;
                                        }
                                        data_set($orderMeta, 'rejection_notes', implode("\n", $orderLines));
                                        data_set($orderMeta, 'rejected_at', now()->toISOString());

                                        // Determine payment status
                                        $orderPaid = strtolower((string) $order->payment_status) === 'paid';
                                        $newPayment = $orderPaid ? 'refunded' : $order->payment_status;

                                        $order->forceFill([
                                            'status'         => 'rejected',
                                            'booking_status' => 'rejected',
                                            'payment_status' => $newPayment,
                                            'meta'           => $orderMeta,
                                        ])->save();
                                    }
                                } catch (\Throwable $e) {
                                    // swallow to avoid breaking the UI; consider logging if needed
                                }

                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');

                                return redirect(\App\Filament\Resources\PendingOrders\Pages\ListPendingOrders::getUrl());
                            }),
                        Action::make('addAdminNote')
                            ->label('Add Admin Note')
                            ->color('primary')
                            ->icon('heroicon-o-document-check')
                            ->form([
                                Textarea::make('new_note')
                                    ->label('Add New Note')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ])
                            ->action(function (PendingOrder $record, array $data, Action $action) {
                                $newNote = trim($data['new_note'] ?? '');
                                if ($newNote === '') {
                                    return;
                                }
                                $timestamp = now()->format('d-m-Y H:i');
                                $toAppend = $timestamp . ': ' . $newNote;
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $existing = data_get($meta, 'admin_notes', '');
                                data_set($meta, 'admin_notes', $existing ? ($existing . "\n" . $toAppend) : $toAppend);
                                $record->meta = $meta;
                                $record->save();
                                $record->refresh();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                                $action->success();
                            }),
                        Action::make('clearAdminNotes')
                            ->label('Clear Admin Notes')
                            ->color('warning')
                            ->icon('heroicon-o-trash')
                            ->requiresConfirmation()
                            ->action(function (PendingOrder $record, Action $action) {
                                $meta = $record->meta ?? [];
                                if (is_array($meta)) {
                                    unset($meta['admin_notes']);
                                }
                                $record->meta = $meta;
                                $record->save();
                                $record->refresh();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                                $action->success();
                            }),
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPendingOrders::route('/'),
            'edit' => EditPendingOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user'])
            ->where(function (Builder $q) {
                $q->whereRaw("LOWER(status) IN ('pending','awaiting_approval','awaiting confirmation','awaiting_confirmation')")
                  ->orWhereRaw("LOWER(booking_status) IN ('pending','awaiting_approval','awaiting confirmation','awaiting_confirmation')");
            });
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = static::getEloquentQuery()->count();
        } catch (\Throwable $e) {
            $count = 0;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        try {
            $count = static::getEloquentQuery()->count();
        } catch (\Throwable $e) {
            $count = 0;
        }

        return $count > 0 ? 'warning' : 'gray';
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
