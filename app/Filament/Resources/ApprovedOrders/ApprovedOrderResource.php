<?php

namespace App\Filament\Resources\ApprovedOrders;

use Carbon\Carbon;
use Throwable;
use Illuminate\Support\Collection;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\HtmlString;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\ViewEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Log;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\ApprovedOrders\Pages\ListApprovedOrders;
use App\Filament\Resources\ApprovedOrders\Schemas\ApprovedOrderForm;
use App\Models\ApprovedOrder;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Consultations\StartConsultation;
use App\Models\ConsultationSession;
use Filament\Forms\Components\Placeholder;

class ApprovedOrderResource extends Resource
{
    // ✅ point to orders model (scoped to approved)
    protected static ?string $model = ApprovedOrder::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

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

                TextColumn::make('patient_priority_dot')
                    ->label('Priority')
                    ->getStateUsing(function ($record) {
                        // 1) Try patient-level priority
                        $p = optional($record->user)->priority ?? null;

                        // 2) Fall back to this pending order's meta.priority
                        if (!is_string($p) || trim($p) === '') {
                            $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                            $p = data_get($meta, 'priority');
                        }

                        // 3) Fall back to the real order's meta.priority by reference
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                $order = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                if ($order) {
                                    $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                    $p = data_get($om, 'priority');
                                }
                            } catch (\Throwable $e) {
                                // ignore
                            }
                        }

                        $p = is_string($p) ? strtolower(trim($p)) : null;
                        return in_array($p, ['red','yellow','green'], true) ? $p : 'green';
                    })
                    ->formatStateUsing(function ($state) {
                        // Render a small coloured dot instead of emoji for cleaner UI
                        $colour = match ($state) {
                            'red' => '#ef4444',
                            'yellow' => '#eab308',
                            'green' => '#22c55e',
                            default => '#22c55e',
                        };
                        $label = ucfirst(is_string($state) ? $state : 'green');
                        return '<span title="' . e($label) . '" style="display:inline-block;width:12px;height:12px;border-radius:9999px;background:' . $colour . ';"></span>';
                    })
                    ->tooltip(function ($record) {
                        $p = optional($record->user)->priority;
                        if (!is_string($p) || trim($p) === '') {
                            $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                            $p = data_get($meta, 'priority');
                        }
                        if (!is_string($p) || trim($p) === '') {
                            try {
                                $order = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                if ($order) {
                                    $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                    $p = data_get($om, 'priority');
                                }
                            } catch (\Throwable $e) {}
                        }
                        $p = is_string($p) ? strtolower(trim($p)) : null;
                        $p = in_array($p, ['red','yellow','green'], true) ? $p : 'green';
                        return ucfirst($p);
                    })
                    ->html()
                    ->extraAttributes(['style' => 'text-align:center; width:5rem']),
                    
                TextColumn::make('created_at')
                    ->label('Order Created')
                    ->dateTime('d M Y, H:i')
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
                            if ($value instanceof Collection) {
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
                        $norm = fn ($v) => strtolower(trim((string) $v));

                        $raw  = $norm(data_get($meta, 'type'));
                        $mode = $norm(data_get($meta, 'mode') ?? data_get($meta, 'flow'));
                        $path = $norm(data_get($meta, 'path') ?? data_get($meta, 'source_url') ?? data_get($meta, 'referer'));
                        $svc  = $norm(data_get($meta, 'service') ?? data_get($meta, 'serviceName') ?? data_get($meta, 'title') ?? '');
                        $ref  = strtoupper((string) ($record->reference ?? ''));

                        $isReorder = in_array($raw, ['reorder','repeat','re-order','repeat-order'], true)
                            || in_array($mode, ['reorder','repeat'], true)
                            || str_contains($path, '/reorder')
                            || preg_match('/^PTC[A-Z]*R[0-9]{6}$/', $ref)
                            || str_contains($svc, 'reorder') || str_contains($svc, 'repeat') || str_contains($svc, 're-order');

                        $isNhs = ($raw === 'nhs')
                            || preg_match('/^PTC[A-Z]*H[0-9]{6}$/', $ref)
                            || str_contains($svc, 'nhs');

                        $isNew = ($raw === 'new')
                            || preg_match('/^PTC[A-Z]*N[0-9]{6}$/', $ref);

                        if ($isReorder) return 'Reorder';
                        if ($isNhs)     return 'NHS';
                        if ($isNew)     return 'New';
                        return null;
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
                    ->searchable(true, function (Builder $query, string $search): Builder {
                        $like = '%' . $search . '%';

                        return $query->where(function (Builder $q) use ($like) {
                            // Search common name/email shapes inside meta JSON
                            $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.firstName')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.first_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.lastName')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.last_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.full_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) LIKE ?", [$like])

                              // Patient/customer nested shapes
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.first_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.last_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.patient.name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.first_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.last_name')) LIKE ?", [$like])
                              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.customer.name')) LIKE ?", [$like])

                              // Related user model (first_name/last_name/email)
                              ->orWhereHas('user', function ($uq) use ($like) {
                                  $uq->where('first_name', 'like', $like)
                                     ->orWhere('last_name', 'like', $like)
                                     ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", [$like])
                                     ->orWhere('email', 'like', $like);
                              })

                              // Direct order reference search
                              ->orWhere('reference', 'like', $like);
                        });
                    })
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'new' => 'New',
                        'nhs' => 'NHS',
                        'reorder' => 'Reorder',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $val = strtolower((string)($data['value'] ?? ''));
                        if ($val === '') return $query;

                        return $query->where(function (Builder $q) use ($val) {
                            // meta.type exact
                            $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) = ?", [$val])
                              ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"'))) = ?", [$val]);

                            // reference patterns
                            if ($val === 'reorder') {
                                $q->orWhere('reference', 'REGEXP', '^PTC[A-Z]*R[0-9]{6}$');
                            } elseif ($val === 'nhs') {
                                $q->orWhere('reference', 'REGEXP', '^PTC[A-Z]*H[0-9]{6}$');
                            } elseif ($val === 'new') {
                                $q->orWhere('reference', 'REGEXP', '^PTC[A-Z]*N[0-9]{6}$');
                            }

                            // service name hints
                            if ($val === 'reorder') {
                                $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE '%reorder%'")
                                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE '%repeat%'")
                                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE '%reorder%'")
                                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE '%repeat%'")
                                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE '%reorder%'")
                                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE '%repeat%'");
                            } elseif ($val === 'nhs') {
                                $q->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE '%nhs%'")
                                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE '%nhs%'")
                                  ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE '%nhs%'");
                            }
                        });
                    }),
            ])
            ->recordActions([
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
                    ->modalDescription(fn ($record) => new HtmlString(
                        '<span class="text-xs text-gray-400">Order Ref: ' . e($record->reference ?? '—') . '</span>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->schema([
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
                                                try { return Carbon::parse($state)->format('d-m-Y'); } catch (Throwable) { return $state; }
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
                        Section::make('Admin notes')
                            ->columnSpanFull()
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
                                RepeatableEntry::make('products')
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
                                        TextEntry::make('products_total_label')
                                            ->hiddenLabel()
                                            ->default('Total')
                                            ->columnSpan(10)
                                            ->extraAttributes(['class' => 'text-right font-medium']),
                                        TextEntry::make('products_total_minor')
                                            ->hiddenLabel()
                                            ->label('')
                                            ->columnSpan(2)
                                            ->getStateUsing(function ($record) {
                                                $meta = is_array($record->meta)
                                                    ? $record->meta
                                                    : (json_decode($record->meta ?? '[]', true) ?: []);
                                                return data_get($meta, 'products_total_minor');
                                            })
                                            ->formatStateUsing(function ($state) {
                                                if ($state === null || $state === '') {
                                                    return '£0.00';
                                                }
                                                return '£' . number_format(((int) $state) / 100, 2);
                                            })
                                            ->placeholder('£0.00')
                                            ->extraAttributes(['class' => 'text-right tabular-nums']),
                                    ]),
                            ]),
                        Section::make('Assessment Answers')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                ViewEntry::make('consultation_qa')
                                    ->label(false)
                                    ->getStateUsing(function ($record) {
                                        // First try formsQA from the ApprovedOrder meta
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $forms = data_get($meta, 'formsQA', []);
                                        // tolerate stringified JSON
                                        if (is_string($forms)) {
                                            $decoded = json_decode($forms, true);
                                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                $forms = $decoded;
                                            }
                                        }

                                        // If empty, fall back to the real Order row by reference
                                        if (empty($forms)) {
                                            try {
                                                $order = Order::where('reference', $record->reference)->first();
                                                if ($order) {
                                                    $ometa = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                                    $forms = data_get($ometa, 'formsQA', []);
                                                    // tolerate stringified JSON
                                                    if (is_string($forms)) {
                                                        $decoded = json_decode($forms, true);
                                                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                            $forms = $decoded;
                                                        }
                                                    }
                                                }
                                            } catch (Throwable $e) {
                                                // ignore
                                            }
                                        }

                                        return $forms;
                                    })
                                    ->view('filament.pending-orders.consultation-qa')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->extraModalFooterActions([
                    // Start Consultation action with reorder aware selection
                    Action::make('startConsultation')
                        ->label('Start Consultation')
                        ->color('success')
                        ->icon('heroicon-o-play')
                        ->action(function (ApprovedOrder $record) {

                            // Load meta as array
                            $meta = is_array($record->meta)
                                ? $record->meta
                                : (json_decode($record->meta ?? '[]', true) ?: []);

                            // Decide desired form type with broader reorder hints
                            $desiredType = 'risk_assessment';

                            // 1 explicit query or stored hints
                            $rawType = strtolower((string) (
                                request()->query('mode')
                                ?? request()->query('type')
                                ?? data_get($meta, 'consultation.mode')
                                ?? data_get($meta, 'consultation.type')
                                ?? data_get($meta, 'mode')
                                ?? data_get($meta, 'type')
                                ?? ''
                            ));

                            // 2 meta order type and reference pattern
                            $orderType = strtolower((string) (
                                data_get($meta, 'order.type')
                                ?? data_get($meta, 'order.meta.type')
                                ?? ''
                            ));
                            $ref = (string) ($record->reference ?? data_get($meta, 'reference') ?? '');

                            $refIsReorder = (bool) preg_match('/^PTC[A-Z]*R[0-9]{6}$/', $ref);

                            // 3 boolean flags
                            $isReorderFlag = false;
                            foreach (['is_reorder', 'reorder', 'flags.reorder'] as $k) {
                                $v = data_get($meta, $k);
                                if (is_bool($v)) $isReorderFlag = $isReorderFlag || $v;
                                elseif (is_numeric($v)) $isReorderFlag = $isReorderFlag || ((int) $v === 1);
                                elseif (is_string($v)) $isReorderFlag = $isReorderFlag || in_array(strtolower($v), ['1','true','yes','y'], true);
                            }

                            if (in_array($rawType, ['reorder'], true) || $orderType === 'reorder' || $refIsReorder || $isReorderFlag) {
                                $desiredType = 'reorder';
                            }

                            // Persist the intent so downstream resolvers can pick the right form
                            data_set($meta, 'consultation.mode', $desiredType);
                            data_set($meta, 'consultation.type', $desiredType);

                            // Pull answers from common paths
                            $answers = data_get($meta, 'assessment.answers')
                                ?? data_get($meta, 'assessment_answers')
                                ?? data_get($meta, 'answers')
                                ?? null;

                            // Normalize answers to string JSON
                            $answersJson = null;
                            if (is_string($answers)) {
                                $decoded = json_decode($answers, true);
                                if (json_last_error() === JSON_ERROR_NONE) $answersJson = $answers;
                            } elseif (is_array($answers)) {
                                $answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }

                            if ($answersJson !== null) {
                                data_set($meta, 'assessment.answers', $answersJson);
                                data_set($meta, 'assessment_answers', $answersJson);
                                data_set($meta, 'answers', $answersJson);
                            }

                            // Save meta before starting consultation
                            $record->meta = $meta;
                            $record->save();

                            // Store a durable snapshot on the order
                            if ($answers !== null) {
                                $answersArray = is_array($answers) ? $answers : (json_decode((string) $answers, true) ?: []);
                                data_set($meta, 'assessment_snapshot', $answersArray);
                                $record->meta = $meta;
                                $record->save();

                                if ($desiredType === 'risk_assessment') {
                                    $existingSid = (int) (data_get($meta, 'consultation_session_id') ?? 0);
                                    if ($existingSid > 0) {
                                        \Illuminate\Support\Facades\DB::table('consultation_form_responses')->updateOrInsert(
                                            ['consultation_session_id' => $existingSid, 'form_type' => 'risk_assessment'],
                                            [
                                                'clinic_form_id' => null,
                                                'data'          => $answersJson ?? json_encode($answersArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                                'is_complete'   => 1,
                                                'completed_at'  => now(),
                                                'updated_at'    => now(),
                                                'created_at'    => now(),
                                            ]
                                        );
                                    }
                                }
                            }

                            // Start consultation
                            $starter = app(\App\Services\Consultations\StartConsultation::class);
                            try {
                                /** @var \App\Models\ConsultationSession $session */
                                $session = $starter($record, ['desired_type' => $desiredType]);
                            } catch (\ArgumentCountError $e) {
                                $session = $starter($record);
                            }

                            // If a new session exists and we have answers copy them only for risk assessment flow
                            if (isset($session) && isset($session->id) && ($answersJson !== null) && $desiredType === 'risk_assessment') {
                                \Illuminate\Support\Facades\DB::table('consultation_form_responses')->updateOrInsert(
                                    ['consultation_session_id' => (int) $session->id, 'form_type' => 'risk_assessment'],
                                    [
                                        'clinic_form_id' => null,
                                        'data'          => $answersJson,
                                        'is_complete'   => 1,
                                        'completed_at'  => now(),
                                        'updated_at'    => now(),
                                        'created_at'    => now(),
                                    ]
                                );
                            }

                            // Try to persist the session form_type if the attribute exists on the model
                            try {
                                $attrs = method_exists($session, 'getAttributes') ? $session->getAttributes() : [];
                                if (array_key_exists('form_type', $attrs)) {
                                    $session->form_type = $desiredType;
                                    $session->save();
                                }
                            } catch (\Throwable $e) {
                                // non-fatal
                            }

                            // Build redirect with explicit step routing when reorder
                            $params = [
                                'session' => $session->id,
                            ];

                            if ($desiredType === 'reorder') {
                                if (\Illuminate\Support\Facades\Route::has('consultations.runner.reorder')) {
                                    return redirect()->route('consultations.runner.reorder', $params);
                                }
                                if (\Illuminate\Support\Facades\Route::has('consultations.reorder')) {
                                    return redirect()->route('consultations.reorder', $params);
                                }
                                // direct path fallback
                                return redirect()->to(url("/admin/consultations/{$session->id}/reorder"));
                            }

                            // Fallback to generic start route with type hint
                            return redirect()->route('consultations.runner.start', $params);
                        }),

                    Action::make('editProduct')
                        ->label('Edit product')
                        ->color('warning')
                        ->icon('heroicon-o-pencil-square')
                        ->modalHeading('Edit product')
                        ->form(function ($record): array {
                            // Decode meta safely
                            $meta = is_array($record->meta)
                                ? $record->meta
                                : (json_decode($record->meta ?? '[]', true) ?: []);

                            // Try to locate the primary line item from common locations
                            $items = null;
                            foreach ([
                                'items',
                                'lines',
                                'products',
                                'line_items',
                                'cart.items',
                            ] as $path) {
                                $arr = data_get($meta, $path);
                                if (is_array($arr) && count($arr)) {
                                    $items = $arr;
                                    break;
                                }
                            }

                            // If we have a single associative product, wrap it as one line
                            if (is_array($items)) {
                                $isList = array_keys($items) === range(0, count($items) - 1);
                                if (
                                    ! $isList &&
                                    (isset($items['name']) || isset($items['title']) || isset($items['product_name']))
                                ) {
                                    $items = [$items];
                                }
                            }

                            $first = (is_array($items) && count($items)) ? $items[0] : [];

                            $name = '';
                            $variation = '';
                            $qty = 1;
                            $unitMinor = null;

                            if (is_array($first)) {
                                $name = (string) ($first['name'] ?? $first['title'] ?? $first['product_name'] ?? '');
                                $qty  = (int) ($first['qty'] ?? $first['quantity'] ?? 1);
                                if ($qty < 1) {
                                    $qty = 1;
                                }

                                // Resolve a variation/strength label from common keys
                                $variation = (string) (
                                    data_get($first, 'variations')
                                    ?? data_get($first, 'variation')
                                    ?? data_get($first, 'optionLabel')
                                    ?? data_get($first, 'variant')
                                    ?? data_get($first, 'dose')
                                    ?? data_get($first, 'strength')
                                    ?? data_get($first, 'option')
                                    ?? ''
                                );

                                // Try to find a per-unit minor price
                                foreach ([
                                    'unitMinor', 'unit_minor', 'unitPriceMinor', 'unit_price_minor',
                                    'unitPricePennies', 'unit_price_pennies',
                                    'priceMinor', 'price_minor', 'priceInMinor', 'priceInPence',
                                ] as $key) {
                                    if (array_key_exists($key, $first) && $first[$key] !== null && $first[$key] !== '') {
                                        $val = $first[$key];
                                        if (is_numeric($val)) {
                                            $unitMinor = (int) $val;
                                        }
                                        break;
                                    }
                                }

                                // Fallback to a totalMinor-style field divided by qty
                                if ($unitMinor === null) {
                                    foreach ([
                                        'lineTotalMinor', 'line_total_minor', 'totalMinor', 'total_minor',
                                        'amountMinor', 'amount_minor',
                                    ] as $key) {
                                        if (array_key_exists($key, $first) && $first[$key] !== null && $first[$key] !== '') {
                                            $val = $first[$key];
                                            if (is_numeric($val)) {
                                                $totalMinor = (int) $val;
                                                $unitMinor = (int) round($totalMinor / max(1, $qty));
                                                break;
                                            }
                                        }
                                    }
                                }
                            }

                            $unitPrice = $unitMinor !== null
                                ? number_format($unitMinor / 100, 2, '.', '')
                                : '';

                            return [
                                TextInput::make('name')
                                    ->label('Product name')
                                    ->default($name)
                                    ->required(),
                                TextInput::make('variation')
                                    ->label('Variation or strength')
                                    ->default($variation),
                                TextInput::make('qty')
                                    ->label('Quantity')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default($qty ?: 1)
                                    ->required(),
                                TextInput::make('unit_price')
                                    ->label('Unit price £')
                                    ->numeric()
                                    ->default($unitPrice)
                                    ->required(),
                            ];
                        })
                        ->action(function ($record, array $data): void {
                            // Decode meta safely
                            $meta = is_array($record->meta)
                                ? $record->meta
                                : (json_decode($record->meta ?? '[]', true) ?: []);

                            // Locate items array again
                            $items = null;
                            $itemsPath = null;
                            foreach ([
                                'items',
                                'lines',
                                'products',
                                'line_items',
                                'cart.items',
                            ] as $path) {
                                $arr = data_get($meta, $path);
                                if (is_array($arr) && count($arr)) {
                                    $items = $arr;
                                    $itemsPath = $path;
                                    break;
                                }
                            }

                            // If we have a single associative product, wrap it
                            if (is_array($items)) {
                                $isList = array_keys($items) === range(0, count($items) - 1);
                                if (
                                    ! $isList &&
                                    (isset($items['name']) || isset($items['title']) || isset($items['product_name']))
                                ) {
                                    $items = [$items];
                                }
                            }

                            if (! is_array($items) || empty($items)) {
                                $items = [[]];
                                $itemsPath = $itemsPath ?? 'items';
                            }

                            $qty = max(1, (int) ($data['qty'] ?? 1));
                            $name = trim((string) ($data['name'] ?? ''));
                            $variation = trim((string) ($data['variation'] ?? ''));
                            $unitPrice = (float) ($data['unit_price'] ?? 0);
                            $unitMinor = (int) round($unitPrice * 100);
                            if ($unitMinor < 0) {
                                $unitMinor = 0;
                            }
                            $lineTotalMinor = $unitMinor * $qty;

                            // Update the first line item
                            $first = $items[0] ?? [];
                            if (! is_array($first)) {
                                $first = [];
                            }

                            $first['name'] = $name !== '' ? $name : ($first['name'] ?? 'Item');
                            $first['qty'] = $qty;
                            $first['quantity'] = $qty;

                            if ($variation !== '') {
                                $first['variations'] = $variation;
                                $first['variation'] = $variation;
                                $first['optionLabel'] = $variation;
                            }

                            $first['unitMinor'] = $unitMinor;
                            $first['unit_minor'] = $unitMinor;
                            $first['priceMinor'] = $unitMinor;
                            $first['price_minor'] = $unitMinor;

                            $first['lineTotalMinor'] = $lineTotalMinor;
                            $first['line_total_minor'] = $lineTotalMinor;
                            $first['totalMinor'] = $lineTotalMinor;
                            $first['total_minor'] = $lineTotalMinor;

                            $items[0] = $first;

                            // Write items back into meta at the same path we found them
                            if ($itemsPath === null) {
                                $meta['items'] = $items;
                            } else {
                                data_set($meta, $itemsPath, $items);
                            }

                            // Also update selectedProduct shape if present or if this is a single-product order
                            $sp = is_array(data_get($meta, 'selectedProduct'))
                                ? data_get($meta, 'selectedProduct')
                                : [];

                            $sp['name'] = $first['name'] ?? $name;
                            if ($variation !== '') {
                                $sp['variations'] = $variation;
                                $sp['variation'] = $variation;
                                $sp['optionLabel'] = $variation;
                            }
                            $sp['qty'] = $qty;
                            $sp['quantity'] = $qty;
                            $sp['priceMinor'] = $unitMinor;
                            $sp['price_minor'] = $unitMinor;
                            $sp['lineTotalMinor'] = $lineTotalMinor;
                            $sp['line_total_minor'] = $lineTotalMinor;

                            $meta['selectedProduct'] = $sp;

                            // Recalculate a simple products_total_minor sum
                            $sum = 0;
                            foreach ($items as $it) {
                                if (! is_array($it)) {
                                    continue;
                                }
                                $line = null;
                                foreach ([
                                    'lineTotalMinor',
                                    'line_total_minor',
                                    'totalMinor',
                                    'total_minor',
                                    'amountMinor',
                                    'amount_minor',
                                ] as $k) {
                                    if (isset($it[$k]) && is_numeric($it[$k])) {
                                        $line = (int) $it[$k];
                                        break;
                                    }
                                }
                                if ($line === null && isset($it['unitMinor'])) {
                                    $line = (int) $it['unitMinor'] * max(1, (int) ($it['qty'] ?? $it['quantity'] ?? 1));
                                }
                                if ($line !== null) {
                                    $sum += $line;
                                }
                            }
                            if ($sum > 0) {
                                // Store the total only inside meta
                                $meta['products_total_minor'] = $sum;
                            }

                            // Save back to the approved order
                            $record->meta = $meta;
                            $record->save();

                            Notification::make()
                                ->success()
                                ->title('Product updated')
                                ->body('Product details have been updated for this order.')
                                ->send();
                        }),

                        Action::make('addAdminNote')
                                ->label('Add Admin Note')
                                ->color('primary')
                                ->icon('heroicon-o-document-check')
                                ->form([
                                    Textarea::make('new_note')
                                        ->label('Add New Note')
                                        ->hiddenLabel()
                                        ->rows(4)
                                        ->columnSpanFull()
                                        ->extraAttributes(['id' => 'new_admin_note_textarea']),
                                    Placeholder::make('dictate_toolbar_new_note')
                                        ->hiddenLabel()
                                        ->label(false)
                                        ->content(function () {
                                            return new \Illuminate\Support\HtmlString(<<<'HTMLX'
                                    <div
                                        class="flex items-center gap-3 mt-2"
                                        wire:ignore
                                        wire:ignore.self
                                        x-data="{
                                            listening:false, rec:null, userStopped:false, status:'Mic off',

                                            root(){ return $el.closest('[data-dialog]') || $el.closest('.fi-modal') || document },
                                            ta(){
                                                return this.root().querySelector('textarea#new_admin_note_textarea')
                                                    || this.root().querySelector('textarea[name=&quot;data[new_note]&quot;]')
                                                    || this.root().querySelector('textarea[name=&quot;new_note&quot;], textarea[name$=&quot;[new_note]&quot;]')
                                                    || this.root().querySelector('textarea');
                                            },
                                            push(txt){
                                                const el=this.ta(); if(!el){ this.status='Textarea not found'; return }
                                                const chunk=String(txt||'').trim(); if(!chunk) return
                                                const space = el.value && !/\\s$/.test(el.value) ? ' ' : ''
                                                el.value += space + chunk
                                                el.dispatchEvent(new Event('input', {bubbles:true}))
                                                el.dispatchEvent(new Event('change', {bubbles:true}))
                                            },
                                            async ensurePerm(){
                                                try{ const s=await navigator.mediaDevices.getUserMedia({audio:true}); (s.getTracks?.()||[]).forEach(t=>t.stop()); return true }
                                                catch(e){ this.status='Mic blocked'; return false }
                                            },
                                            start(){
                                                const SR = window.SpeechRecognition || window.webkitSpeechRecognition
                                                if(!SR){ this.status='Not supported'; return }
                                                this.userStopped=false
                                                this.rec=new SR(); this.rec.lang='en-GB'; this.rec.continuous=true; this.rec.interimResults=true
                                                this.rec.onresult = e => {
                                                    let fin='', tmp=''
                                                    for(let i=e.resultIndex;i<e.results.length;i++){
                                                        const r=e.results[i]
                                                        if(r.isFinal) fin += r[0].transcript
                                                        else tmp += r[0].transcript
                                                    }
                                                    if(fin) this.push(fin)
                                                    this.status = this.listening ? ('Listening… ' + tmp.trim()) : 'Mic off'
                                                }
                                                this.rec.onstart = ()=>{ this.listening=true; this.status='Listening…' }
                                                this.rec.onerror = e=>{ this.status='Error ' + (e?.error ?? '') }
                                                this.rec.onend   = ()=>{
                                                    this.listening=false; this.status='Mic off'
                                                    if(!this.userStopped){ try{ this.rec.start() }catch(_){} }
                                                }
                                                try{ this.rec.start() }catch(_){ this.status='Start failed' }
                                            },
                                            async toggle(){
                                                if(!this.listening){
                                                    if(await this.ensurePerm()) this.start()
                                                }else{
                                                    this.userStopped=true
                                                    try{ this.rec?.stop() }catch(_){}
                                                    this.listening=false; this.status='Mic off'
                                                }
                                            }
                                        }"
                                        >
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-3 px-3 py-1.5 rounded-md text-sm font-medium
                                                bg-green-600/20 hover:bg-green-600/30 transition"
                                            x-bind:class="{ 'bg-red-600/20 hover:bg-red-600/30': listening }"
                                            x-on:click="toggle()"
                                        >
                                            <!-- mic icon -->
                                            <svg x-show="!listening" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                class="w-4 h-4" fill="currentColor" aria-hidden="true">
                                                <path d="M12 14a4 4 0 0 0 4-4V7a4 4 0 1 0-8 0v3a4 4 0 0 0 4 4Zm-7-4a1 1 0 0 1 2 0 5 5 0 1 0 10 0 1 1 0 1 1 2 0 7 7 0 0 1-6 6.93V20h3a1 1 0 1 1 0 2H10a1 1 0 1 1 0-2h3v-3.07A7 7 0 0 1 5 10Z"/>
                                            </svg>

                                            <!-- stop icon -->
                                            <svg x-show="listening" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                class="w-4 h-4" fill="currentColor" aria-hidden="true">
                                                <path d="M6 6h12v12H6z"/>
                                            </svg>

                                            <!-- labels -->
                                            <span x-show="!listening">Start dictation</span>
                                            <span x-show="listening">Stop dictation</span>
                                        </button>

                                        <!-- status (spaced away from button) -->
                                        <span class="inline-flex items-center gap-2 text-xs opacity-80 ml-3">
                                            <span class="inline-block w-2 h-2 rounded-full bg-gray-400"
                                                x-bind:class="listening ? 'bg-emerald-400 animate-pulse' : 'bg-gray-400'"></span>

                                            <span x-text="status"></span>
                                        </span>
                                        </div>
                                    HTMLX);
                                        })
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
                            ->action(function (ApprovedOrder $record, Action $action) {
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
            ->whereRaw("LOWER(booking_status) IN ('approved','booked')")
            ->where(function (Builder $q) {
                $q->whereNull('status')
                  ->orWhere('status', '!=', 'completed');
            });
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = static::getEloquentQuery()->count();
        } catch (Throwable $e) {
            $count = 0;
        }
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        try {
            $count = static::getEloquentQuery()->count();
        } catch (Throwable $e) {
            $count = 0;
        }
        return $count > 0 ? 'primary' : 'gray';
    }
    public function startConsultationAction($record)
    {
        // Normalise meta
        $meta = is_array($record->meta)
            ? $record->meta
            : (json_decode($record->meta ?? '[]', true) ?: []);

        // Resolve desired type: query wins then meta then heuristic
        $rq = function (string $k): ?string {
            try {
                $v = request()->query($k);
                return is_string($v) ? strtolower($v) : null;
            } catch (\Throwable $e) {
                return null; // not in HTTP ctx
            }
        };

        $desiredType = $rq('type') ?: $rq('mode')
            ?: strtolower((string) (
                data_get($meta, 'consultation.type')
                ?: data_get($meta, 'consultation.mode')
                ?: data_get($meta, 'type')
                ?: ''
            ));

        if ($desiredType === '' || $desiredType === null) {
            // simple reorder heuristic
            $isReorder = false;
            foreach (['is_reorder','reorder','flags.reorder'] as $k) {
                $v = data_get($meta, $k);
                if (is_bool($v)) $isReorder = $isReorder || $v;
                elseif (is_numeric($v)) $isReorder = $isReorder || ((int)$v === 1);
                elseif (is_string($v)) $isReorder = $isReorder || in_array(strtolower($v), ['1','true','yes'], true);
            }
            $desiredType = $isReorder ? 'reorder' : 'risk_assessment';
        }

        // Persist type intent so downstream resolvers can pick the right form
        data_set($meta, 'consultation.type', $desiredType);
        data_set($meta, 'consultation.mode', $desiredType);
        $record->meta = $meta;
        $record->save();

        /** @var \App\Models\ConsultationSession $session */
        $starter = app(\App\Services\Consultations\StartConsultation::class);
        try {
            $session = $starter($record, ['desired_type' => $desiredType]);
        } catch (\ArgumentCountError $e) {
            $session = $starter($record);
        }

        // Try to persist the session form_type if available
        try {
            if (isset($session) && isset($session->id) && property_exists($session, 'form_type')) {
                $session->form_type = $desiredType;
                $session->save();
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        $params = [
            'session' => $session->id,
        ];

        if ($desiredType === 'reorder') {
            if (\Illuminate\Support\Facades\Route::has('consultations.runner.reorder')) {
                return redirect()->route('consultations.runner.reorder', $params);
            }
            if (\Illuminate\Support\Facades\Route::has('consultations.reorder')) {
                return redirect()->route('consultations.reorder', $params);
            }
        }

        return redirect()->route('consultations.runner.start', $params);
    }
}