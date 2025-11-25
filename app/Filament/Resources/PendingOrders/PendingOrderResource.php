<?php

namespace App\Filament\Resources\PendingOrders;

use Carbon\Carbon;
use Throwable;
use Illuminate\Support\Collection;
use Filament\Tables\Filters\SelectFilter;
use App\Filament\Resources\PendingOrders\Pages\CreatePendingOrder;
use App\Filament\Resources\PendingOrders\Pages\EditPendingOrder;
use App\Filament\Resources\PendingOrders\Pages\ListPendingOrders;
use App\Filament\Resources\PendingOrders\Schemas\PendingOrderForm;
use App\Filament\Resources\PendingOrders\Tables\PendingOrdersTable;
use Filament\Actions\Action;
use App\Models\PendingOrder;
use App\Models\Appointment;
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
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderApprovedMail;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Notifications\Notification;


class PendingOrderResource extends Resource
{
    protected static ?string $model = PendingOrder::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Pending Approval';
    protected static ?string $pluralLabel = 'Pending Approval';
    protected static ?string $modelLabel = 'Pending Approval';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(FilamentSchema $filamentSchema): FilamentSchema
    {
        return PendingOrderForm::configure($filamentSchema);
    }

    public static function table(Table $table): Table
    {
        return $table
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

                
                TextColumn::make('service_name')
                    ->label('Order Service')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                        $name = data_get($meta, 'service')
                            ?: data_get($meta, 'serviceName')
                            ?: data_get($meta, 'treatment')
                            ?: data_get($meta, 'title');

                        // fallback or override from consultation session slug
                        $sid = data_get($meta, 'consultation_session_id')
                            ?? data_get($meta, 'consultation.sessionId');

                        if ($sid) {
                            try {
                                $slug = DB::table('consultation_sessions')
                                    ->where('id', $sid)
                                    ->value('service_slug');

                                if ($slug) {
                                    $fromSlug = ucwords(str_replace(['-', '_'], ' ', $slug));
                                    // prefer the slug source if meta name is missing or looks generic
                                    if (!$name || stripos($name, 'weight management') !== false || stripos($name, 'service') !== false) {
                                        $name = $fromSlug;
                                    }
                                }
                            } catch (Throwable $e) {
                                // ignore
                            }
                        }

                        return $name ?: 'Service';
                    }),

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

                // Appointment Type (New / Transfer / Reorder)
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
                            || preg_match('/^PTC[A-Z]*R\d{6}$/', $ref)
                            || str_contains($svc, 'reorder') || str_contains($svc, 'repeat') || str_contains($svc, 're-order');

                        $isNhs = ($raw === 'nhs')
                            || preg_match('/^PTC[A-Z]*H\d{6}$/', $ref)
                            || str_contains($svc, 'nhs');

                        $isNew = ($raw === 'new')
                            || preg_match('/^PTC[A-Z]*N\d{6}$/', $ref);

                        if ($isReorder) return 'Reorder';
                        if ($isNhs)     return 'NHS';
                        if ($isNew)     return 'New';
                        return null;
                    })
                    ->toggleable(),

                TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(function ($record) {
                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                        $pick = function ($arr, array $keys) {
                            foreach ($keys as $k) {
                                $v = data_get($arr, $k);
                                if (is_string($v) && trim($v) !== '') {
                                    return trim($v);
                                }
                            }
                            return null;
                        };

                        // Try structured first and last names across many common shapes
                        $first = $pick($meta, [
                            'firstName', 'first_name', 'patient.firstName', 'patient.first_name',
                            'customer.first_name', 'customer.given_name', 'contact.first_name', 'answers.first_name',
                            'personal.first_name', 'personal.firstName', 'form.personal.first_name', 'form.personal.firstName',
                            'billingAddress.first_name', 'shippingAddress.first_name', 'patient.first',
                        ]);

                        $last = $pick($meta, [
                            'lastName', 'last_name', 'patient.lastName', 'patient.last_name',
                            'customer.last_name', 'customer.family_name', 'contact.last_name', 'answers.last_name',
                            'personal.last_name', 'personal.lastName', 'form.personal.last_name', 'form.personal.lastName',
                            'billingAddress.last_name', 'shippingAddress.last_name', 'patient.last',
                        ]);

                        // Full name fields if present
                        $full = $pick($meta, [
                            'name', 'full_name', 'fullName',
                            'patient.name', 'patient.full_name',
                            'customer.name', 'contact.name', 'answers.name',
                        ]);

                        if (!$full) {
                            $combined = trim(trim((string) $first) . ' ' . trim((string) $last));
                            if ($combined !== '') {
                                $full = $combined;
                            }
                        }

                        // Fall back to the related user, if any
                        if (!$full && isset($record->user)) {
                            $userCombined = trim(trim((string) ($record->user->first_name ?? '')) . ' ' . trim((string) ($record->user->last_name ?? '')));
                            $full = $userCombined !== '' ? $userCombined : ($record->user->name ?? null);
                        }

                        return $full ?: '—';
                    })
                    ->searchable()
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
                    ->modalDescription(function ($record) {
                        $ref = e($record->reference ?? '—');
                        return new HtmlString('<span class="text-xs text-gray-400">Order Ref: ' . $ref . '</span>');
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->schema([
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
                                                try { return Carbon::parse($state)->format('d-m-Y'); } catch (Throwable) { return $state; }
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
                                        \Filament\Infolists\Components\TextEntry::make('appointment_datetime')->hiddenLabel(),
                                    ]),
                                ]),
                        ]),
                        Section::make('Admin notes')
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('meta.admin_notes')
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
                                        TextEntry::make('products_total_label')
                                            ->hiddenLabel()
                                            ->default('Total')
                                            ->columnSpan(10)
                                            ->extraAttributes(['class' => 'text-right font-medium']),
                                        TextEntry::make('products_total_minor')
                                            ->label('') // no header over value cell
                                            ->hiddenLabel()
                                            ->columnSpan(2)
                                            ->getStateUsing(fn ($record) => $record->products_total_minor)
                                            ->formatStateUsing(fn ($state) => '£' . number_format(((int) $state) / 100, 2))
                                            ->placeholder('£0.00')
                                            ->extraAttributes(['class' => 'text-right tabular-nums']),
                                    ]),
                            ]),

                        // Consultation QA snapshot (RAF only — rendered via Blade)
                        Section::make('Assessment Answers')
                            ->collapsible()
                            ->schema([
                                ViewEntry::make('consultation_qa')
                                    ->label(false)
                                    ->getStateUsing(function ($record) {
                                        // First try formsQA from the PendingOrder meta
                                        $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                        $forms = data_get($meta, 'formsQA', []);

                                        // If empty, fall back to the real Order row by reference
                                        if (empty($forms)) {
                                            try {
                                                $order = Order::where('reference', $record->reference)->first();
                                                if ($order) {
                                                    $ometa = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                                    $forms = data_get($ometa, 'formsQA', []);
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
                        Action::make('set_priority')
                            ->label('Priority')
                            ->icon('heroicon-o-flag')
                            ->color('warning')
                            ->modalHeading('Set patient priority')
                            ->form([
                                Select::make('priority')
                                    ->options([
                                        'red' => 'Red',
                                        'yellow' => 'Yellow',
                                        'green' => 'Green',
                                    ])
                                    ->required()
                                    ->default(function (\App\Models\PendingOrder $record) {
                                        $u = optional($record->user);
                                        return $u?->priority ?: 'green';
                                    })
    
                            ])
                            ->action(function (\App\Models\PendingOrder $record, \Filament\Actions\Action $action, array $data) {
                                $prio = $data['priority'] ?? null;

                                if (!in_array($prio, ['red','yellow','green'], true)) {
                                    \Filament\Notifications\Notification::make()
                                        ->danger()
                                        ->title('Choose a valid priority')
                                        ->send();
                                    return;
                                }

                                // Persist on the patient
                                try {
                                    if ($record->user) {
                                        $record->user->forceFill(['priority' => $prio])->save();
                                    }
                                } catch (\Throwable $e) {
                                    // ignore
                                }

                                // Also copy onto this pending order so the UI reflects immediately
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    $meta['priority'] = $prio;
                                    $record->forceFill(['meta' => $meta])->save();
                                } catch (\Throwable $e) {
                                    // ignore
                                }

                                \Filament\Notifications\Notification::make()
                                    ->success()
                                    ->title('Priority updated')
                                    ->send();

                                // Refresh the table/modal
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                            }),
                        Action::make('setScrVerified')
                            ->label('SCR Verified')
                            ->color('gray')
                            ->icon('heroicon-o-check-badge')
                            ->form([
                        Select::make('value')
                            ->label('Set SCR status')
                            ->options([
                                'yes' => 'Yes',
                                'no'  => 'No',
                            ])
                            ->required()
                            ->default(function ($record) {
                                $norm = function ($v) {
                                    if (in_array($v, [true, 1, '1', 'true', 'yes', 'YES', 'Yes'], true)) return 'yes';
                                    if (in_array($v, [false, 0, '0', 'false', 'no', 'NO', 'No'], true)) return 'no';
                                    return null;
                                };

                                // 1) Pending order meta
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                $v = $norm(data_get($meta, 'scr_verified'));
                                if ($v !== null) return $v;

                                // 2) User meta
                                try {
                                    $u = $record->user ?? null;
                                    if ($u) {
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                            $um = is_array($u->meta) ? $u->meta : (json_decode($u->meta ?? '[]', true) ?: []);
                                            $uv = $norm(data_get($um, 'scr_verified'));
                                            if ($uv !== null) return $uv;
                                        }
                                        // 3) User flat column
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified')) {
                                            $flat = $norm($u->scr_verified ?? null);
                                            if ($flat !== null) return $flat;
                                        }
                                    }
                                } catch (\Throwable $e) {}

                                // 4) Linked order by reference
                                try {
                                    $ord = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                    if ($ord) {
                                        $om = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                        $ov = $norm(data_get($om, 'scr_verified'));
                                        if ($ov !== null) return $ov;
                                    }
                                } catch (\Throwable $e) {}

                                return null; // no preselection
                            })
                            ])
                            ->action(function (PendingOrder $record, Action $action, array $data) {
                                $setYes = ($data['value'] ?? null) === 'yes';

                                // Update on PendingOrder
                                $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                data_set($meta, 'scr_verified', $setYes);
                                if ($setYes) {
                                    data_set($meta, 'scr_verified_at', now()->toIso8601String());
                                } else {
                                    data_forget($meta, 'scr_verified_at');
                                }
                                $record->meta = $meta;
                                $record->save();

                                // Mirror to the corresponding Order, if found by reference
                                try {
                                    $order = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                    if ($order) {
                                        $om = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                                        data_set($om, 'scr_verified', $setYes);
                                        if ($setYes) {
                                            data_set($om, 'scr_verified_at', now()->toIso8601String());
                                        } else {
                                            data_forget($om, 'scr_verified_at');
                                        }
                                        $order->meta = $om;
                                        $order->save();
                                    }
                                } catch (\Throwable $e) {
                                    // swallow to keep UX smooth
                                }

                                // Persist on patient profile so future orders auto-populate
                                try {
                                    $user = $record->user ?? null;
                                    if ($user) {
                                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                            $um = is_array($user->meta) ? $user->meta : (json_decode($user->meta ?? '[]', true) ?: []);
                                            data_set($um, 'scr_verified', $setYes);
                                            if ($setYes) {
                                                data_set($um, 'scr_verified_at', now()->toIso8601String());
                                            } else {
                                                data_forget($um, 'scr_verified_at');
                                            }
                                            $user->meta = $um;
                                            $user->save();
                                        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified')) {
                                            $user->scr_verified = $setYes ? 1 : 0;
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified_at')) {
                                                $user->scr_verified_at = $setYes ? now() : null;
                                            }
                                            $user->save();
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    // swallow
                                }

                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                            }),
                        Action::make('approve')
                            ->label('Approve')
                            ->color('success')
                            ->icon('heroicon-o-check')
                            ->action(function (PendingOrder $record, Action $action) {
                                // SCR must be explicitly chosen once per patient or order before approval
                                try {
                                    $norm = function ($v) {
                                        if (in_array($v, [true, 1, '1', 'true', 'yes', 'YES', 'Yes'], true)) return 'yes';
                                        if (in_array($v, [false, 0, '0', 'false', 'no', 'NO', 'No'], true)) return 'no';
                                        return null;
                                    };

                                    // 1) Check this pending order meta
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    $scr = $norm(data_get($meta, 'scr_verified'));

                                    // 2) Fall back to linked Order by reference
                                    if ($scr === null) {
                                        try {
                                            $ord = \App\Models\Order::where('reference', $record->reference)->latest()->first();
                                            if ($ord) {
                                                $om = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                                $scr = $norm(data_get($om, 'scr_verified'));
                                            }
                                        } catch (\Throwable $e) { /* ignore */ }
                                    }

                                    // 3) Fall back to user profile
                                    if ($scr === null && $record->user) {
                                        try {
                                            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'meta')) {
                                                $um = is_array($record->user->meta) ? $record->user->meta : (json_decode($record->user->meta ?? '[]', true) ?: []);
                                                $scr = $norm(data_get($um, 'scr_verified'));
                                            }
                                            if ($scr === null && \Illuminate\Support\Facades\Schema::hasColumn('users', 'scr_verified')) {
                                                $scr = $norm($record->user->scr_verified ?? null);
                                            }
                                        } catch (\Throwable $e) { /* ignore */ }
                                    }

                                    if ($scr === null) {
                                        \Filament\Notifications\Notification::make()
                                            ->danger()
                                            ->title('SCR required')
                                            ->body('Choose SCR yes or no')
                                            ->send();
                                        try { $action->getLivewire()->dispatch('$refresh'); $action->getLivewire()->dispatch('refreshTable'); } catch (\Throwable $e) {}
                                        return; // block approval until a choice has been made
                                    }
                                } catch (\Throwable $e) {
                                    \Filament\Notifications\Notification::make()
                                        ->danger()
                                        ->title('SCR check failed')
                                        ->body('Could not confirm SCR selection. Please choose Yes or No and try again.')
                                        ->send();
                                    try { $action->getLivewire()->dispatch('$refresh'); $action->getLivewire()->dispatch('refreshTable'); } catch (\Throwable $ex) {}
                                    return;
                                }

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
                                } catch (Throwable $e) {
                                    // swallow to avoid breaking the UI; consider logging if needed
                                }
                                // Email the patient that the order is approved once
                                try {
                                    $metaForEmail = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                                    if (!data_get($metaForEmail, 'emailed.order_approved_at')) {
                                        // Find the related Order row
                                        $orderForEmail = \App\Models\Order::where('reference', $record->reference)->latest()->first();

                                        // Resolve recipient email from Order first, then meta, then user
                                        $orderMetaArr = [];
                                        if ($orderForEmail) {
                                            $orderMetaArr = is_array($orderForEmail->meta) ? $orderForEmail->meta : (json_decode($orderForEmail->meta ?? '[]', true) ?: []);
                                        }

                                        $to = $orderForEmail->email
                                            ?? data_get($orderMetaArr, 'customer.email')
                                            ?? data_get($metaForEmail, 'email')
                                            ?? data_get($metaForEmail, 'customer.email')
                                            ?? optional($record->user)->email;

                                        if ($to && $orderForEmail) {
                                            Mail::to($to)->queue(new OrderApprovedMail($orderForEmail));

                                            // Flag on both PendingOrder and Order to prevent duplicate sends
                                            data_set($metaForEmail, 'emailed.order_approved_at', now()->toIso8601String());
                                            $record->meta = $metaForEmail;
                                            $record->save();

                                            $om = $orderMetaArr;
                                            data_set($om, 'emailed.order_approved_at', now()->toIso8601String());
                                            $orderForEmail->meta = $om;
                                            $orderForEmail->save();
                                        }
                                    }
                                } catch (Throwable $e) {
                                    // swallow
                                }
                                // After syncing the Order, approve or create the matching appointment so it only shows once approved
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                    // Extract appointment window from meta
                                    $start = data_get($meta, 'appointment_start_at')
                                        ?? data_get($meta, 'appointment.start_at')
                                        ?? data_get($meta, 'appointment_at')
                                        ?? data_get($meta, 'booking.start_at');

                                    $end = data_get($meta, 'appointment_end_at')
                                        ?? data_get($meta, 'appointment.end_at')
                                        ?? data_get($meta, 'booking.end_at');

                                    // Build candidate service slugs
                                    $serviceCandidates = [];
                                    $sid = data_get($meta, 'consultation_session_id')
                                        ?? data_get($meta, 'consultation.sessionId');
                                    if ($sid) {
                                        $slug = DB::table('consultation_sessions')
                                            ->where('id', $sid)
                                            ->value('service_slug');
                                        if (is_string($slug) && $slug !== '') {
                                            $serviceCandidates[] = $slug;
                                        }
                                    }
                                    foreach (['service_slug','service','serviceName','title','treatment'] as $k) {
                                        $v = data_get($meta, $k);
                                        if (is_string($v) && $v !== '') {
                                            $serviceCandidates[] = \Illuminate\Support\Str::slug($v);
                                        }
                                    }
                                    $serviceCandidates = array_values(array_unique(array_filter($serviceCandidates)));

                                    $startAt = null;
                                    $endAt = null;
                                    try { if ($start) { $startAt = Carbon::parse($start); } } catch (Throwable $e) { $startAt = null; }
                                    try { if ($end)   { $endAt   = Carbon::parse($end);   } } catch (Throwable $e) { $endAt = null; }

                                    // First try a direct match via order_reference if the column exists
                                    $updatedAny = false;
                                    if (DBSchema::hasColumn('appointments', 'order_reference')) {
                                        $rows = DB::table('appointments')->where('order_reference', $record->reference)->get();
                                        if ($rows->count() > 0) {
                                            $update = [];
                                            if (DBSchema::hasColumn('appointments', 'status'))         $update['status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'booking_status')) $update['booking_status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'is_visible'))     $update['is_visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'visible'))        $update['visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'updated_at'))     $update['updated_at'] = now();

                                            DB::table('appointments')->where('order_reference', $record->reference)->update($update);
                                            $updatedAny = true;
                                        }
                                    }

                                    // Otherwise, match by service and near start time
                                    if (!$updatedAny && !empty($serviceCandidates) && $startAt) {
                                        $q = DB::table('appointments')->whereIn('service', $serviceCandidates);
                                        // Match a 30-minute window to be resilient to seconds
                                        $q->whereBetween('start_at', [
                                            $startAt->copy()->subMinutes(30),
                                            $startAt->copy()->addMinutes(30),
                                        ]);
                                        $rows = $q->get();
                                        if ($rows->count() > 0) {
                                            $update = [];
                                            if (DBSchema::hasColumn('appointments', 'status'))         $update['status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'booking_status')) $update['booking_status'] = 'approved';
                                            if (DBSchema::hasColumn('appointments', 'is_visible'))     $update['is_visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'visible'))        $update['visible'] = 1;
                                            if (DBSchema::hasColumn('appointments', 'order_reference')) $update['order_reference'] = $record->reference;
                                            if (DBSchema::hasColumn('appointments', 'user_id') && isset($record->user_id)) $update['user_id'] = $record->user_id;
                                            if (DBSchema::hasColumn('appointments', 'updated_at'))     $update['updated_at'] = now();

                                            DB::table('appointments')
                                                ->whereIn('service', $serviceCandidates)
                                                ->whereBetween('start_at', [
                                                    $startAt->copy()->subMinutes(30),
                                                    $startAt->copy()->addMinutes(30),
                                                ])
                                                ->update($update);
                                            $updatedAny = true;
                                        }
                                    }

                                    // If nothing matched, create a row so it appears only post-approval
                                    if (!$updatedAny && ($startAt || !empty($serviceCandidates))) {
                                        $insert = [];
                                        $insert['service']  = $serviceCandidates[0] ?? (\Illuminate\Support\Str::slug((string) (data_get($meta, 'service') ?? data_get($meta, 'serviceName') ?? data_get($meta, 'title') ?? data_get($meta, 'treatment') ?? 'service')));
                                        if ($startAt) $insert['start_at'] = $startAt->toDateTimeString();
                                        if ($endAt)   $insert['end_at']   = $endAt->toDateTimeString();
                                        if (DBSchema::hasColumn('appointments', 'status'))         $insert['status'] = 'approved';
                                        if (DBSchema::hasColumn('appointments', 'booking_status')) $insert['booking_status'] = 'approved';
                                        if (DBSchema::hasColumn('appointments', 'is_visible'))     $insert['is_visible'] = 1;
                                        if (DBSchema::hasColumn('appointments', 'visible'))        $insert['visible'] = 1;
                                        if (DBSchema::hasColumn('appointments', 'order_reference')) $insert['order_reference'] = $record->reference;
                                        if (DBSchema::hasColumn('appointments', 'user_id') && isset($record->user_id)) $insert['user_id'] = $record->user_id;
                                        if (DBSchema::hasColumn('appointments', 'created_at'))     $insert['created_at'] = now();
                                        if (DBSchema::hasColumn('appointments', 'updated_at'))     $insert['updated_at'] = now();

                                        DB::table('appointments')->insert($insert);
                                    }
                                } catch (Throwable $e) {
                                    // swallow
                                }
                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');
                                return redirect(ListPendingOrders::getUrl());
                            }),
                        Action::make('reject')
                            ->label('Reject')
                            ->color('danger')
                            ->icon('heroicon-o-x-mark')
                            ->modalHeading('Reject Order')
                            ->modalDescription('Please provide a reason. This note will be saved with the order.')
                            ->modalSubmitActionLabel('Reject')
                            ->requiresConfirmation(false)
                            ->schema([
                                Textarea::make('rejection_note')
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
                                } catch (Throwable $e) {
                                    // swallow to avoid breaking the UI; consider logging if needed
                                }

                                // On rejection, remove or hide any matching appointment so it disappears from the list
                                // On rejection, mark any matching appointment as rejected (don't delete)
                                try {
                                    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);

                                    $start = data_get($meta, 'appointment_start_at')
                                        ?? data_get($meta, 'appointment.start_at')
                                        ?? data_get($meta, 'appointment_at')
                                        ?? data_get($meta, 'booking.start_at');

                                    // Build candidate service slugs
                                    $serviceCandidates = [];
                                    $sid = data_get($meta, 'consultation_session_id')
                                        ?? data_get($meta, 'consultation.sessionId');
                                    if ($sid) {
                                        $slug = DB::table('consultation_sessions')
                                            ->where('id', $sid)
                                            ->value('service_slug');
                                        if (is_string($slug) && $slug !== '') {
                                            $serviceCandidates[] = $slug;
                                        }
                                    }
                                    foreach (['service_slug','service','serviceName','title','treatment'] as $k) {
                                        $v = data_get($meta, $k);
                                        if (is_string($v) && $v !== '') {
                                            $serviceCandidates[] = \Illuminate\Support\Str::slug($v);
                                        }
                                    }
                                    $serviceCandidates = array_values(array_unique(array_filter($serviceCandidates)));

                                    $startAt = null;
                                    try { if ($start) { $startAt = Carbon::parse($start); } } catch (Throwable $e) { $startAt = null; }

                                    // Build update payload
                                    $update = [];
                                    if (DBSchema::hasColumn('appointments', 'status'))         $update['status'] = 'rejected';
                                    if (DBSchema::hasColumn('appointments', 'booking_status')) $update['booking_status'] = 'rejected';
                                    if (DBSchema::hasColumn('appointments', 'is_visible'))     $update['is_visible'] = 1; // keep visible so you can see it’s rejected
                                    if (DBSchema::hasColumn('appointments', 'visible'))        $update['visible'] = 1;
                                    if (DBSchema::hasColumn('appointments', 'updated_at'))     $update['updated_at'] = now();

                                    $updatedAny = false;

                                    // Prefer a direct match via order_reference if present
                                    if (DBSchema::hasColumn('appointments', 'order_reference')) {
                                        $affected = DB::table('appointments')
                                            ->where('order_reference', $record->reference)
                                            ->update($update);
                                        $updatedAny = $affected > 0;
                                    }

                                    // Otherwise match by service and near start time
                                    if (!$updatedAny && !empty($serviceCandidates) && $startAt) {
                                        $payload = $update;
                                        if (DBSchema::hasColumn('appointments', 'order_reference')) {
                                            $payload['order_reference'] = $record->reference;
                                        }

                                        DB::table('appointments')
                                            ->whereIn('service', $serviceCandidates)
                                            ->whereBetween('start_at', [
                                                $startAt->copy()->subMinutes(30),
                                                $startAt->copy()->addMinutes(30),
                                            ])
                                            ->update($payload);
                                    }
                                } catch (Throwable $e) {
                                    // swallow
                                }

                                $action->success();
                                $action->getLivewire()->dispatch('$refresh');
                                $action->getLivewire()->dispatch('refreshTable');

                                return redirect(ListPendingOrders::getUrl());
                            }),
                        Action::make('addAdminNote')
                            ->label('Add Admin Note')
                            ->color('primary')
                            ->icon('heroicon-o-document-check')
                            ->schema([
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

        return $count > 0 ? 'warning' : 'gray';
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
