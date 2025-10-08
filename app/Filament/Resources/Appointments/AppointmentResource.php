<?php

namespace App\Filament\Resources\Appointments;

use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\TextEntry;

class AppointmentResource extends Resource
{
    protected static ?string $model = \App\Models\Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Approved Orders';
    protected static ?string $pluralLabel = 'Approved Orders';
    protected static ?string $modelLabel = 'Approved Orders';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'service';

    public static function form(Schema $schema): Schema
    {
        return AppointmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('appointment_start_at', 'desc')
            ->columns([
                TextColumn::make('booking_datetime')
                    ->label('Order Date & Time')
                    ->getStateUsing(function ($record) {
                        $start = $record->appointment_start_at ?? null;
                        $end   = $record->appointment_end_at ?? null;
                        if (! $start) return null;
                        $fmt = function ($dt) {
                            try { return \Illuminate\Support\Carbon::parse($dt)->format('d-m-Y H:i'); } catch (\Throwable) { return (string) $dt; }
                        };
                        return $end ? ($fmt($start) . ' — ' . $fmt($end)) : $fmt($start);
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('appointment_start_at', $direction))
                    ->toggleable(),
                
                TextColumn::make('appointment_name')
                    ->label('Order Service')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('order_item')
                    ->label('Order Item')
                    ->getStateUsing(function ($record) {
                        // Helpers
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
                            }
                            return is_array($value) ? $value : [];
                        };
                        $lineToString = function ($it) {
                            if (is_string($it)) return '1 × ' . $it;
                            if (!is_array($it)) return null;
                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                            if ($qty < 1) $qty = 1;
                            $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? null;
                            $opt  = $it['variant'] ?? $it['dose'] ?? $it['strength'] ?? $it['option'] ?? null;
                            $parts = [];
                            $parts[] = $qty . ' ×';
                            if ($name) $parts[] = $name;
                            if ($opt)  $parts[] = $opt;
                            return trim(implode(' ', $parts)) ?: null;
                        };

                        // Prefer products on booking
                        $candidates = [
                            data_get($record, 'products'),
                            data_get($record, 'order.meta.items'),
                            data_get($record, 'order.meta.products'),
                            data_get($record, 'order.meta.lines'),
                            data_get($record, 'order.meta.line_items'),
                            data_get($record, 'order.meta.cart.items'),
                            data_get($record, 'order.items'),
                            data_get($record, 'order.products'),
                            data_get($record, 'order.lines'),
                            data_get($record, 'order.line_items'),
                        ];
                        $items = [];
                        foreach ($candidates as $cand) {
                            $arr = $normalize($cand);
                            if (!empty($arr)) { $items = $arr; break; }
                        }

                        $labels = [];
                        foreach ($items as $it) {
                            $s = $lineToString($it);
                            if ($s) $labels[] = $s;
                        }

                        // Single-item fallbacks from meta if nothing found
                        if (empty($labels)) {
                            $meta = is_array($record->order?->meta)
                                ? $record->order->meta
                                : (json_decode($record->order->meta ?? '[]', true) ?: []);
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
                            $opt  = data_get($meta, 'strength')
                                ?? data_get($meta, 'dose')
                                ?? data_get($meta, 'variant')
                                ?? data_get($meta, 'option')
                                ?? data_get($meta, 'selectedProduct.strength')
                                ?? data_get($meta, 'selected_product.strength')
                                ?? data_get($meta, 'medication.strength')
                                ?? data_get($meta, 'drug.strength')
                                ?? data_get($meta, 'product.strength')
                                ?? data_get($meta, 'item.strength')
                                ?? data_get($meta, 'line.strength')
                                ?? data_get($meta, 'cart.item.strength');
                            if ($name) {
                                $labels[] = trim($qty . ' × ' . $name . ($opt ? ' ' . $opt : ''));
                            }
                        }

                        return !empty($labels) ? implode("\n", $labels) : null;
                    })
                    ->formatStateUsing(fn ($state) => $state ? nl2br(e($state)) : null)
                    ->html()
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('appointment_type')
                    ->label('Type')
                    ->getStateUsing(function ($record) {
                        // Prefer an explicit appointment_type if present on booking
                        $type = $record->appointment_type ?? null;

                        // If missing, infer from the linked order's meta
                        if (!is_string($type) || $type === '') {
                            $meta = $record->order && is_array($record->order->meta)
                                ? $record->order->meta
                                : (json_decode($record->order->meta ?? '[]', true) ?: []);
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

                

                TextColumn::make('patient_first_name')
                    ->label('First Name')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('patient_last_name')
                    ->label('Last Name')
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
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        $val = strtolower((string)($data['value'] ?? ''));
                        if ($val === '') {
                            return $query;
                        }

                        $aliases = match ($val) {
                            'reorder' => ['reorder', 'repeat', 're-order'],
                            'new' => ['new', 'nhs'],
                            default => [$val],
                        };

                        return $query->where(function ($q) use ($aliases) {
                            // booking.appointment_type (if column exists it will be populated; whereRaw avoids casts)
                            if (\Illuminate\Support\Facades\Schema::hasColumn('bookings', 'appointment_type')) {
                                $placeholders = implode(',', array_fill(0, count($aliases), '?'));
                                $q->orWhereRaw("LOWER(appointment_type) IN ($placeholders)", $aliases);
                            }

                            // order.meta fallbacks (JSON type and service/serviceName/title search)
                            $q->orWhereHas('order', function ($oq) use ($aliases) {
                                $placeholders = implode(',', array_fill(0, count($aliases), '?'));
                                $oq->where(function ($qq) use ($aliases, $placeholders) {
                                    $qq->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) IN ($placeholders)", $aliases)
                                       ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"'))) IN ($placeholders)", $aliases);
                                });
                                foreach ($aliases as $a) {
                                    if (in_array($a, ['reorder','repeat','re-order'])) {
                                        $needle = '%reorder%';
                                        $oq->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", [$needle])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", [$needle])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", [$needle]);
                                    } elseif ($a === 'consultation') {
                                        $needle = '%consult%';
                                        $oq->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", [$needle])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", [$needle])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", [$needle]);
                                    } elseif ($a === 'new' || $a === 'nhs') {
                                        $oq->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", ['%nhs%'])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", ['%new%'])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", ['%nhs%'])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", ['%new%'])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", ['%nhs%'])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", ['%new%']);
                                    } else {
                                        $needle = "%$a%";
                                        $oq->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service'))) LIKE ?", [$needle])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.serviceName'))) LIKE ?", [$needle])
                                           ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.title'))) LIKE ?", [$needle]);
                                    }
                                }
                            });
                        });
                    }),
            ])
            ->actions([
                Action::make('viewBooking')
                    ->label('View Order')
                    ->button()
                    ->color('primary')
                    ->modalHeading(fn ($record) => ($record->appointment_name ?? 'Order Details'))
                    ->modalDescription(fn ($record) => new \Illuminate\Support\HtmlString(
                        '<span class="text-xs text-gray-400">' .
                        e($record->order && $record->order->reference ? ('Order Ref: ' . $record->order->reference) : ('Order ID: ' . $record->id)) .
                        '</span>'
                    ))
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
                                            ->label('First Name'),
                                        TextEntry::make('patient_last_name')
                                            ->label('Last Name'),
                                        TextEntry::make('dob')
                                            ->label('DOB')
                                            ->formatStateUsing(function ($state, $record) {
                                                $dob = $record->dob ?? null;
                                                if (empty($dob)) return null;
                                                try { return \Illuminate\Support\Carbon::parse($dob)->format('d-m-Y'); } catch (\Throwable) { return $dob; }
                                            }),
                                        TextEntry::make('patient_email')
                                            ->label('Email')
                                            ->state(function ($record) {
                                                if (!empty($record->patient_email)) return $record->patient_email;
                                                return optional(optional($record->order)->user)->email;
                                            }),
                                        TextEntry::make('patient_phone')
                                            ->label('Phone')
                                            ->state(function ($record) {
                                                if (!empty($record->patient_phone)) return $record->patient_phone;
                                                return optional(optional($record->order)->user)->phone;
                                            }),
                                        TextEntry::make('created_at')
                                            ->label('Created At')
                                            ->dateTime('d-m-Y H:i'),
                                    ]),
                                ]),
                            // RIGHT COLUMN: Appointment Date & Time
                            Grid::make(1)
                                ->columnSpan(4)
                                ->schema([
                                    Section::make('Payment Details')
                                        ->schema([
                                            TextEntry::make('order.payment_status')
                                                ->label('Payment status')
                                                ->hiddenLabel()
                                                ->state(fn ($record) => $record->order->payment_status ?? null)
                                                ->badge()
                                                ->color(function ($state) {
                                                    $s = strtolower((string) $state);
                                                    return $s === 'paid' ? 'success' : ($s === 'unpaid' ? 'warning' : 'gray');
                                                }),
                                        ]),
                                    Section::make('Appointment Date & Time')
                                        ->schema([
                                            TextEntry::make('appointment_start_at')
                                                ->label('Order Date & Time')
                                                ->hiddenLabel()
                                                ->state(function ($record) {
                                                    $start = $record->appointment_start_at ?? null;
                                                    $end   = $record->appointment_end_at ?? null;
                                                    if (! $start) {
                                                        return null;
                                                    }
                                                    $fmt = function ($dt) {
                                                        try { return \Illuminate\Support\Carbon::parse($dt)->format('d-m-Y H:i'); } catch (\Throwable) { return (string) $dt; }
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
                                        TextEntry::make('patient_notes')
                                            ->hiddenLabel()
                                            ->state(fn ($record) => $record->patient_notes ?: 'No patient notes provided')
                                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->html(),
                                    ]),

                                Section::make('Admin Notes')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('admin_notes')
                                            ->hiddenLabel()
                                            ->state(function ($record) {
                                                // Always fetch fresh from DB to avoid stale relation cache on Booking model
                                                if (\Illuminate\Support\Facades\Schema::hasColumn('bookings', 'admin_notes') && $record) {
                                                    try {
                                                        $fresh = \App\Models\Booking::query()->whereKey($record->getKey())->value('admin_notes');
                                                    } catch (\Throwable $e) {
                                                        $fresh = null;
                                                    }
                                                    if (!empty($fresh)) {
                                                        return (string) $fresh;
                                                    }
                                                }
                                                // Fallback to order meta admin_notes if booking column is empty/nonexistent
                                                $meta = is_array(optional($record->order)->meta)
                                                    ? $record->order->meta
                                                    : (json_decode(optional($record->order)->meta ?? '[]', true) ?: []);
                                                $fallback = (string) (data_get($meta, 'admin_notes') ?: '—');
                                                return $fallback;
                                            })
                                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                                            ->extraAttributes(function ($record) {
                                                $ts = optional($record->updated_at)->timestamp ?? time();
                                                return ['wire:key' => 'approved-admin-notes-' . $record->getKey() . '-' . $ts];
                                            })
                                            ->html(),
                                    ]),
                            ]),

                        // Products (prefer booking.products)
                        Section::make(function ($record) {
                            $items = [];
                            if (is_array(data_get($record, 'products'))) {
                                $items = data_get($record, 'products');
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
                                        // Collect possible sources
                                        $items = null;
                                        $candidates = [
                                            'products',
                                            'order.meta.products',
                                            'order.meta.items',
                                            'order.meta.lines',
                                            'order.meta.line_items',
                                            'order.meta.cart.items',
                                            'order.items',
                                            'order.products',
                                            'order.lines',
                                            'order.line_items',
                                        ];
                                        foreach ($candidates as $path) {
                                            $arr = data_get($record, $path);
                                            if (is_array($arr) && count($arr)) { $items = $arr; break; }
                                        }
                                        if (!is_array($items) || empty($items)) return [];

                                        $parseMoneyToMinor = function ($value) {
                                            if ($value === null || $value === '') return null;
                                            if (is_int($value)) return $value;
                                            if (is_float($value)) return (int) round($value * 100);
                                            if (is_string($value)) {
                                                $s = trim($value);
                                                $s = preg_replace('/[^\d\.\,\-]/', '', $s);
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
                                                $out[] = ['name' => $it, 'qty' => 1, 'priceMinor' => null];
                                                continue;
                                            }
                                            if (!is_array($it)) continue;

                                            $name = $it['name'] ?? ($it['title'] ?? 'Item');
                                            $qty  = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                                            if ($qty < 1) $qty = 1;

                                            $minorCandidates = [
                                                'lineTotalMinor', 'line_total_minor', 'line_total_pennies', 'lineTotalPennies',
                                                'totalMinor', 'total_minor', 'totalPennies', 'total_pennies',
                                                'amountMinor', 'amount_minor',
                                                'subtotalMinor', 'subtotal_minor',
                                                'priceMinor', 'price_minor', 'priceInMinor', 'priceInPence', 'price_in_minor', 'price_in_pence',
                                                'unitMinor', 'unit_minor', 'unitPriceMinor', 'unit_price_minor', 'unitPricePennies', 'unit_price_pennies',
                                                'minor', 'pennies', 'value_minor', 'valueMinor',
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

                                            if ($priceMinor === null) {
                                                $majorCandidates = [
                                                    'lineTotal', 'line_total', 'linePrice', 'line_price', 'line_total_price',
                                                    'total', 'amount', 'subtotal',
                                                    'price', 'cost',
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
                                                if (is_numeric($maybe)) {
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
                                                    if ($priceMinor === null) {
                                                        $unitParsed = $parseMoneyToMinor($unitMaybeMajor);
                                                        if ($unitParsed !== null) $priceMinor = (int) $unitParsed * $qty;
                                                    }
                                                }
                                            }

                                        $out[] = [
                                            'name' => (string) $name,
                                            'qty' => (string) $qty,
                                            'priceMinor' => $priceMinor,
                                        ];
                                        }
                                        return $out;
                                    })
                                    ->schema([
                                        \Filament\Schemas\Components\Grid::make(12)->schema([
                                            \Filament\Infolists\Components\TextEntry::make('name')->label('Product')->columnSpan(8),
                                            \Filament\Infolists\Components\TextEntry::make('qty')
                                                ->label('Qty')
                                                ->formatStateUsing(function ($state) {
                                                    if (is_numeric($state)) {
                                                        $v = (int) $state;
                                                    } else {
                                                        $v = null;
                                                    }
                                                    if ($v === null || $v < 1) {
                                                        $v = 1;
                                                    }
                                                    return (string) $v;
                                                })
                                                ->default('1')
                                                ->columnSpan(2),
                                            \Filament\Infolists\Components\TextEntry::make('priceMinor')->label('Price')
                                                ->formatStateUsing(function ($minor) {
                                                    if ($minor === null || $minor === '') return '—';
                                                    if (!is_numeric($minor)) return (string) $minor;
                                                    $val = (int) $minor;
                                                    return '£' . number_format($val / 100, 2);
                                                })
                                                ->columnSpan(2),
                                        ]),
                                    ])
                                    ->columnSpanFull(),
                            ]),

                        // Assessment / RAF Form Answers
                        Section::make('Assessment Answers')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                TextEntry::make('form_answers')
                                    ->hiddenLabel()
                                    ->state(function ($record) {
                                        $answers = data_get($record, 'form_answers');
                                        if (empty($answers)) {
                                            return 'No answers submitted';
                                        }
                                        if (is_string($answers)) {
                                            $decoded = json_decode($answers, true);
                                            if (json_last_error() === JSON_ERROR_NONE) {
                                                $answers = $decoded;
                                            }
                                        }
                                        $out = [];
                                        if (is_array($answers)) {
                                            foreach ($answers as $key => $value) {
                                                if (is_array($value)) {
                                                    $value = implode(', ', array_map('strval', $value));
                                                }
                                                $label = ucwords(str_replace(['_', '-'], ' ', (string)$key));
                                                $out[] = '<strong>' . e($label) . ':</strong> ' . e((string)$value);
                                            }
                                        } else {
                                            $out[] = e((string)$answers);
                                        }
                                        return implode('<br>', $out);
                                    })
                                    ->html(),
                            ]),
                    ])
                    ->extraModalFooterActions([
                        Action::make('startConsultation')
                            ->label('Start Consultation')
                            ->color('success')
                            ->icon('heroicon-o-play')
                            ->url(fn ($record) => url('/admin/forms?booking=' . $record->id))
                            ->openUrlInNewTab(),
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
            'index' => Pages\ListAppointments::route('/'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Show approved (and legacy 'booked') bookings and eager-load order+user for fallbacks
        return parent::getEloquentQuery()
            ->with(['order.user'])
            ->whereIn('status', ['approved', 'booked']);
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
}
