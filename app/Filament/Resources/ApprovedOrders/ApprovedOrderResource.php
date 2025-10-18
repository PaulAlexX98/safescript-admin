<?php

namespace App\Filament\Resources\ApprovedOrders;

use App\Filament\Resources\ApprovedOrders\Pages\ListApprovedOrders;
use App\Filament\Resources\ApprovedOrders\Schemas\ApprovedOrderForm;
use App\Models\ApprovedOrder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Consultations\StartConsultation;
use App\Models\ConsultationSession;

class ApprovedOrderResource extends Resource
{
    // ✅ point to orders model (scoped to approved)
    protected static ?string $model = \App\Models\ApprovedOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Approved Orders';
    protected static ?string $pluralLabel = 'Approved Orders';
    protected static ?string $modelLabel = 'Approved Orders';

    protected static ?int $navigationSort = 3;

    // use order reference as record title if you have it
    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return ApprovedOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                // Order date (from meta appointment_start_at if present, else created_at)
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
                    ->sortable()
                    ->toggleable(),

                // Service name from order meta
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

                // Items list (multi-line) from order meta
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

                // Type from order meta
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
                                if (str_contains($t, 'nhs') || str_contains($t, 'new')) $type = 'new';
                                elseif (str_contains($t, 'reorder') || str_contains($t, 'repeat') || str_contains($t, 're-order')) $type = 'reorder';
                                elseif (str_contains($t, 'transfer')) $type = 'transfer';
                                elseif (str_contains($t, 'consult')) $type = 'consultation';
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

                // Combined Customer column
                TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $first = null; $last = null;
                        foreach (['firstName','first_name','patient.firstName','patient.first_name'] as $k) {
                            $v = data_get($meta, $k); if ($v) { $first = $v; break; }
                        }
                        foreach (['lastName','last_name','patient.lastName','patient.last_name'] as $k) {
                            $v = data_get($meta, $k); if ($v) { $last = $v; break; }
                        }
                        if (!$first && isset($record->user)) { $first = $record->user->first_name ?? null; }
                        if (!$last  && isset($record->user)) { $last  = $record->user->last_name  ?? null; }
                        $name = trim(trim((string)$first) . ' ' . trim((string)$last));
                        if ($name === '') {
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
                        if ($val === '') return $query;

                        $aliases = match ($val) {
                            'reorder' => ['reorder', 'repeat', 're-order'],
                            'new' => ['new', 'nhs'],
                            default => [$val],
                        };

                        return $query->where(function (Builder $q) use ($aliases) {
                            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
                            $q->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) IN ($placeholders)", $aliases)
                              ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"'))) IN ($placeholders)", $aliases);

                            foreach ($aliases as $a) {
                                if (in_array($a, ['reorder','repeat','re-order'])) {
                                    $needle = '%reorder%';
                                    $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", [$needle])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", [$needle])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", [$needle]);
                                } elseif ($a === 'consultation') {
                                    $needle = '%consult%';
                                    $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", [$needle])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", [$needle])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", [$needle]);
                                } elseif ($a === 'new' || $a === 'nhs') {
                                    $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", ['%nhs%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", ['%new%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", ['%nhs%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", ['%new%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", ['%nhs%'])
                                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", ['%new%']);
                                } else {
                                    $needle = "%$a%";
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
                    ->modalDescription(fn ($record) => new \Illuminate\Support\HtmlString(
                        '<span class="text-xs text-gray-400">Order Ref: ' . e($record->reference ?? '—') . '</span>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->infolist([
                        Grid::make(12)->schema([
                            Section::make('Patient Details')
                                ->columnSpan(8)
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextEntry::make('patient_first_name')
                                            ->label('First Name')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['firstName','first_name','patient.firstName','patient.first_name'] as $k) {
                                                    $v = data_get($meta, $k);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->first_name ?? null;
                                            }),
                                        TextEntry::make('patient_last_name')
                                            ->label('Last Name')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                foreach (['lastName','last_name','patient.lastName','patient.last_name'] as $k) {
                                                    $v = data_get($meta, $k);
                                                    if ($v) return $v;
                                                }
                                                return $record->user->last_name ?? null;
                                            }),
                                        TextEntry::make('dob')
                                            ->label('DOB')
                                            ->state(function ($record) {
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
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'email') ?: ($record->user->email ?? null);
                                            }),
                                        TextEntry::make('meta.phone')
                                            ->label('Phone')
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'phone') ?: ($record->user->phone ?? null);
                                            }),
                                        TextEntry::make('created_at')
                                            ->label('Created At')
                                            ->dateTime('d-m-Y H:i'),
                                    ]),
                                ]),
                            Grid::make(1)
                                ->columnSpan(4)
                                ->schema([
                                    Section::make('Payment Details')
                                        ->schema([
                                            TextEntry::make('payment_status')
                                                ->label('Payment')
                                                ->formatStateUsing(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return $s === 'paid' ? 'Paid' : ($s === 'unpaid' ? 'Unpaid' : ucfirst((string) $state));
                                                })
                                                ->badge()
                                                ->color(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return $s === 'paid' ? 'success' : ($s === 'unpaid' ? 'warning' : 'gray');
                                                }),
                                        ]),
                                    Section::make('Appointment Date & Time')
                                        ->schema([
                                            TextEntry::make('order_datetime')
                                                ->hiddenLabel()
                                                ->state(function ($record) {
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
                        Section::make('Notes & Comments')
                            ->columnSpanFull()
                            ->schema([
                                Section::make('Patient Notes')
                                    ->schema([
                                        TextEntry::make('meta.patient_notes')
                                            ->hiddenLabel()
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'patient_notes') ?: 'No patient notes provided';
                                            })
                                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->html(),
                                    ]),
                                Section::make('Admin Notes')
                                    ->schema([
                                        TextEntry::make('meta.admin_notes')
                                            ->hiddenLabel()
                                            ->state(function ($record) {
                                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return (string) (data_get($meta, 'admin_notes') ?: '—');
                                            })
                                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->extraAttributes(function ($record) {
                                                $ts = optional($record->updated_at)->timestamp ?? time();
                                                return ['wire:key' => 'approved-admin-notes-' . $record->getKey() . '-' . $ts];
                                            })
                                            ->html(),
                                    ]),
                            ]),
                        Section::make(function ($record) {
                            $items = [];
                            $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                            foreach (['products','items','cart.items','lines','line_items'] as $path) {
                                $arr = data_get($meta, $path);
                                if (is_array($arr) && count($arr)) { $items = $arr; break; }
                            }
                            $count = is_countable($items) ? count($items) : 0;
                            return $count ? 'Products (' . $count . ')' : 'Products';
                        })
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                \Filament\Infolists\Components\RepeatableEntry::make('products')
                                    ->hiddenLabel()
                                    ->getStateUsing(function ($record) {
                                        // Normalize various meta shapes into a uniform array of { name, variation, qty, priceMinor, priceFormatted }
                                        $items = null;
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $candidates = [
                                            'items',
                                            'lines',
                                            'products',
                                            'line_items',
                                            'cart.items',
                                        ];
                                        foreach ($candidates as $path) {
                                            $arr = data_get($meta, $path);
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
                                        
                                        $out = [];
                                        foreach ($items as $it) {
                                            if (is_string($it)) {
                                                $out[] = [
                                                    'name'           => (string) $it,
                                                    'variation'      => '',
                                                    'qty'            => 1,
                                                    'priceMinor'     => null,
                                                    'price'          => null,
                                                    'priceFormatted' => '—',
                                                ];
                                                continue;
                                            }
                                            if (!is_array($it)) continue;
                                        
                                            $name = $it['name'] ?? ($it['title'] ?? 'Item');
                                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                       	                    if ($qty < 1) $qty = 1;
                                        
                                            // Enhanced variation resolution, supports nested keys and arrays
                                            $resolveVar = function ($row) {
                                                $keys = [
                                                    'variations', 'variation', 'optionLabel', 'variant', 'dose', 'strength', 'option',
                                                    'meta.variations', 'meta.variation', 'meta.optionLabel', 'meta.variant', 'meta.dose', 'meta.strength', 'meta.option',
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
                                                $variation = data_get($meta, 'selectedProduct.variations')
                                                    ?? data_get($meta, 'selected_product.variations')
                                                    ?? data_get($meta, 'selectedProduct.variation')
                                                    ?? data_get($meta, 'selected_product.variation')
                                                    ?? data_get($meta, 'selectedProduct.optionLabel')
                                                    ?? data_get($meta, 'selected_product.optionLabel')
                                                    ?? data_get($meta, 'selectedProduct.variant')
                                                    ?? data_get($meta, 'selected_product.variant')
                                                    ?? data_get($meta, 'selectedProduct.dose')
                                                    ?? data_get($meta, 'selected_product.dose')
                                                    ?? data_get($meta, 'selectedProduct.strength')
                                                    ?? data_get($meta, 'selected_product.strength')
                                                    ?? data_get($meta, 'variant')
                                                    ?? data_get($meta, 'dose')
                                                    ?? data_get($meta, 'strength')
                                                    ?? data_get($meta, 'variation')
                                                    ?? data_get($meta, 'option')
                                                    ?? '';
                                                if (is_array($variation)) {
                                                    $variation = is_string($variation['label'] ?? null) ? $variation['label']
                                                        : (is_string($variation['value'] ?? null) ? $variation['value'] : trim(implode(' ', array_map('strval', $variation))));
                                                }
                                            }
                                        
                                            // Try common minor-unit keys first
                                            $minorCandidates = [
                                                'lineTotalMinor','line_total_minor','line_total_pennies','lineTotalPennies',
                                                'totalMinor','total_minor','totalPennies','total_pennies',
                                                'amountMinor','amount_minor',
                                                'subtotalMinor','subtotal_minor',
                                                'priceMinor','price_minor','priceInMinor','priceInPence','price_in_minor','price_in_pence',
                                                'unitMinor','unit_minor','unitPriceMinor','unit_price_minor','unitPricePennies','unit_price_pennies',
                                                'minor','pennies','value_minor','valueMinor',
                                            ];
                                            $unitMinor = null;
                                            $priceMinor = null;
                                            foreach ($minorCandidates as $key) {
                                                if (array_key_exists($key, $it) && $it[$key] !== null && $it[$key] !== '') {
                                                    $val = $it[$key];
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
                                                    'lineTotal','line_total','linePrice','line_price','line_total_price',
                                                    'total','amount','subtotal',
                                                    'price','cost',
                                                    'unitPrice','unit_price','unitCost','unit_cost',
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
                                                    if ($priceMinor === null && $unitMaybeMajor !== null) {
                                                        $priceMinor = (int) $unitMaybeMajor * $qty;
                                                    }
                                                }
                                            }
                                        
                                            // Allow string numeric values for totalMinor/unitMinor
                                            if ($priceMinor === null && array_key_exists('totalMinor', $it) && $it['totalMinor'] !== null && $it['totalMinor'] !== '') {
                                                $priceMinor = (int) $it['totalMinor'];
                                            }
                                        
                                            // As a very last resort, if there is exactly one item and the order has a total, use it
                                            if ($priceMinor === null && $__itemsCount === 1) {
                                                $orderTotalMinor = data_get($meta, 'totalMinor')
                                                    ?? data_get($meta, 'amountMinor')
                                                    ?? data_get($meta, 'total_minor')
                                                    ?? data_get($meta, 'amount_minor');
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
                                        \Filament\Schemas\Components\Grid::make(12)->schema([
                                            \Filament\Infolists\Components\TextEntry::make('name')
                                                ->label('Product')
                                                ->columnSpan(6),
                                            \Filament\Infolists\Components\TextEntry::make('variation')
                                                ->label('Variation')
                                                ->formatStateUsing(fn ($state) => $state ?: '—')
                                                ->columnSpan(3),
                                            \Filament\Infolists\Components\TextEntry::make('qty')
                                                ->label('Qty')
                                                ->formatStateUsing(fn ($state) => (string) $state)
                                                ->columnSpan(1),
                                            \Filament\Infolists\Components\TextEntry::make('priceFormatted')
                                                ->label('Price')
                                                ->columnSpan(2)
                                                ->formatStateUsing(fn ($state) => (string) $state)
                                                ->extraAttributes(['class' => 'text-right whitespace-nowrap']),
                                        ]),
                                    ])
                                    ->columnSpanFull(),
                                \Filament\Schemas\Components\Grid::make(12)
                                    ->columnSpanFull()
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('products_total_label')
                                            ->hiddenLabel()
                                            ->default('Total')
                                            ->columnSpan(10)
                                            ->extraAttributes(['class' => 'text-right font-medium']),
                                        \Filament\Infolists\Components\TextEntry::make('products_total_minor')
                                            ->hiddenLabel()
                                            ->label('')
                                            ->columnSpan(2)
                                            ->getStateUsing(fn ($record) => $record->products_total_minor)
                                            ->formatStateUsing(fn ($state) => '£' . number_format(((int) $state) / 100, 2))
                                            ->placeholder('£0.00')
                                            ->extraAttributes(['class' => 'text-right tabular-nums']),
                                    ]),
                            ]),
                        Section::make('Assessment Answers')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                // Prefer a Blade view renderer if present; it receives $state = answers array
                                \Filament\Infolists\Components\ViewEntry::make('assessment_answers')
                                    ->label(false)
                                    ->getStateUsing(function ($record) {
                                        // --- compute raw answers array (no formatting) ---
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $answers = data_get($meta, 'formAnswers')
                                            ?? data_get($meta, 'assessment')
                                            ?? data_get($meta, 'form_answers')
                                            ?? data_get($meta, 'answers');

                                        $toArray = function ($v) {
                                            if (is_string($v)) {
                                                $d = json_decode($v, true);
                                                if (json_last_error() === JSON_ERROR_NONE) return $d;
                                            }
                                            return $v;
                                        };
                                        $answers = $toArray($answers);

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
                                                $answers = data_get($sessionMetaArr, 'answers') ?? $sessionMetaArr;
                                            }
                                        }

                                        return (is_array($answers) ? $answers : []);
                                    })
                                    ->view('filament/pending-orders/assessment-card') // reuse the same Blade view
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
                                        // 1) Load answers from order meta, else from consultation_sessions
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $answers = data_get($meta, 'formAnswers')
                                            ?? data_get($meta, 'assessment')
                                            ?? data_get($meta, 'form_answers')
                                            ?? data_get($meta, 'answers');

                                        $toArray = function ($v) {
                                            if (is_string($v)) {
                                                $d = json_decode($v, true);
                                                if (json_last_error() === JSON_ERROR_NONE) return $d;
                                            }
                                            return $v;
                                        };
                                        $answers = $toArray($answers);

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
                                                $answers = data_get($sessionMetaArr, 'answers') ?? $sessionMetaArr;
                                            }
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
                            ]),
                    ])
                    ->extraModalFooterActions([
                        // Start Consultation action (refactored)
                        Action::make('startConsultation')
                            ->label('Start Consultation')
                            ->color('success')
                            ->icon('heroicon-o-play')
                            ->action(function (\App\Models\ApprovedOrder $record) {
                                $session = app(\App\Services\Consultations\StartConsultation::class)($record);
                                // Use the new split-page route:
                                return redirect()->to("/admin/consultations/{$session->id}/pharmacist-advice");
                            }),
                        Action::make('addAdminNote')
                            ->label('Add Admin Note')
                            ->color('primary')
                            ->icon('heroicon-o-document-check')
                            ->form([
                                \Filament\Forms\Components\Textarea::make('new_note')
                                    ->label('Add New Note')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ])
                            ->action(function (\App\Models\ApprovedOrder $record, array $data, Action $action) {
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
                            ->action(function (\App\Models\ApprovedOrder $record, Action $action) {
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
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovedOrders::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user'])
            ->whereRaw("LOWER(booking_status) IN ('approved','booked')");
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
        return $count > 0 ? 'primary' : 'gray';
    }
    public function startConsultationAction($record)
    {
        $session = app(StartConsultation::class)($record);
        return redirect()->to("/admin/consultations/{$session->id}/pharmacist-advice");
    }
}