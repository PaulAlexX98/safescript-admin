<?php

namespace App\Filament\Resources\Appointments;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Appointments\Pages as Pages;
use App\Models\Appointment;
 
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\Order;
use App\Models\PendingOrder;
use Illuminate\Support\Arr;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    // Sidebar placement
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Appointments';
    protected static string | \UnitEnum | null $navigationGroup = 'Notifications';
    protected static ?int    $navigationSort  = 5;

    // Title used on View/Edit pages
    protected static ?string $recordTitleAttribute = 'display_title';

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->columns(12)
            ->components([
                \Filament\Forms\Components\Placeholder::make('ref_display')
                    ->label('Ref')
                    ->content(function ($record) {
                        // If linked order exists, show the real reference
                        $ref = static::resolveOrderRef($record);
                        if (is_string($ref) && trim($ref) !== '') {
                            return trim($ref);
                        }

                        // For brand-new appointments (no ID yet), show a temporary PCAO + 6-digit code
                        if (! $record || ! $record->getKey()) {
                            try {
                                $rand = random_int(0, 999999);
                            } catch (\Throwable $e) {
                                $rand = mt_rand(0, 999999);
                            }
                            return 'PCAO' . str_pad((string) $rand, 6, '0', STR_PAD_LEFT);
                        }

                        // For existing records with no linked order, just show a dash
                        return '—';
                    })
                    ->columnSpan(3),
                \Filament\Forms\Components\TextInput::make('service_name')
                    ->label('Item')
                    ->maxLength(191)
                    ->columnSpan(9),

                \Filament\Forms\Components\Hidden::make('start_at')
                    ->required(),
                \Filament\Forms\Components\Hidden::make('end_at')
                    ->dehydrated(fn () => \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'end_at')),

                \Filament\Forms\Components\DatePicker::make('start_date')
                    ->label('Date')
                    ->native(false)
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function ($state, \Filament\Schemas\Components\Utilities\Set $set, $record) {
                        if (! $record || empty($record->start_at)) {
                            return;
                        }
                        try {
                            $dt = \Carbon\Carbon::parse($record->start_at)->tz('Europe/London');
                            $set('start_date', $dt->format('Y-m-d'));
                        } catch (\Throwable $e) {
                        }
                    })
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                        $date = $get('start_date');
                        $time = $get('start_time');
                        if (! $date || ! $time) {
                            $set('start_at', null);
                            $set('end_at', null);
                            return;
                        }
                        try {
                            $dt = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $time, 'Europe/London');
                            $set('start_at', $dt->format('Y-m-d H:i:s'));
                            $set('end_at', $dt->copy()->addMinutes(20)->format('Y-m-d H:i:s'));
                        } catch (\Throwable $e) {
                            $set('start_at', null);
                            $set('end_at', null);
                        }
                    })
                    ->columnSpan(6),

                \Filament\Forms\Components\Select::make('start_time')
                    ->label('Time')
                    ->native(false)
                    ->options(function () {
                        $opts = [];

                        // 08:00 to 18:00 inclusive in 15-minute steps
                        for ($h = 8; $h <= 18; $h++) {
                            $hh = str_pad((string) $h, 2, '0', STR_PAD_LEFT);

                            // only allow 18:00 (no 18:15 etc)
                            if ($h === 18) {
                                $t = $hh . ':00';
                                $opts[$t] = $t;
                                continue;
                            }

                            for ($m = 0; $m < 60; $m += 15) {
                                $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
                                $t = $hh . ':' . $mm;
                                $opts[$t] = $t;
                            }
                        }

                        return $opts;
                    })
                    ->searchable()
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function ($state, \Filament\Schemas\Components\Utilities\Set $set, $record) {
                        if (! $record || empty($record->start_at)) {
                            return;
                        }
                        try {
                            $dt = \Carbon\Carbon::parse($record->start_at)->tz('Europe/London');
                            $t = $dt->format('H:i');

                            [$hh, $mm] = array_pad(explode(':', $t, 2), 2, null);
                            $h = is_numeric($hh) ? (int) $hh : -1;
                            $m = is_numeric($mm) ? (int) $mm : -1;

                            $ok = false;

                            if ($h >= 8 && $h <= 17 && $m >= 0 && $m < 60 && ($m % 15 === 0)) {
                                $ok = true;
                            }

                            if ($h === 18 && $m === 0) {
                                $ok = true;
                            }

                            $set('start_time', $ok ? $t : null);
                        } catch (\Throwable $e) {
                        }
                    })
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                        $date = $get('start_date');
                        $time = $get('start_time');
                        if (! $date || ! $time) {
                            $set('start_at', null);
                            $set('end_at', null);
                            return;
                        }
                        try {
                            $dt = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $time, 'Europe/London');
                            $set('start_at', $dt->format('Y-m-d H:i:s'));
                            $set('end_at', $dt->copy()->addMinutes(20)->format('Y-m-d H:i:s'));
                        } catch (\Throwable $e) {
                            $set('start_at', null);
                            $set('end_at', null);
                        }
                    })
                    ->columnSpan(6),

                \Filament\Forms\Components\Toggle::make('online_consultation')
                    ->label('Online consultation')
                    ->helperText('When on, the patient email includes a Zoom link')
                    ->default(false)
                    ->live()
                    ->dehydrated(fn () => \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'online_consultation'))
                    ->columnSpan(12),

                \Filament\Forms\Components\TextInput::make('first_name')
                    ->label('First name')
                    ->maxLength(191)
                    ->columnSpan(6),

                \Filament\Forms\Components\TextInput::make('last_name')
                    ->label('Last name')
                    ->maxLength(191)
                    ->columnSpan(6),
                
                \Filament\Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(191)
                    ->required()
                    ->columnSpan(12),

                \Filament\Forms\Components\TextInput::make('service')
                    ->label('Service')
                    ->maxLength(191)
                    ->columnSpan(6),

                \Filament\Forms\Components\TextInput::make('service_slug')
                    ->label('Service key')
                    ->hidden()
                    ->dehydrated(false)
                    ->columnSpan(6),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('start_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('reference')
                    ->label('Ref')
                    ->getStateUsing(function ($record) {
                        if (! $record) {
                            return '—';
                        }

                        $ref = static::resolveOrderRef($record);

                        if (is_string($ref)) {
                            $r = trim($ref);
                            return ($r !== '' && $r !== '-') ? $r : '';
                        }
                        return '';
                    })
                    ->toggleable()
                    ->searchable(true, function (Builder $query, string $search): Builder {
                        $like = "%" . $search . "%";

                        return $query->where(function ($q) use ($like) {
                            $q->where('order_reference', 'like', $like)
                            ->orWhereExists(function ($sub) use ($like) {
                                $sub->select(\DB::raw('1'))
                                    ->from('orders')
                                    ->whereColumn('orders.id', 'appointments.order_id')
                                    ->where('orders.reference', 'like', $like);
                            });
                        });
                    }),

                TextColumn::make('order_item')
                    ->label('Item')
                    ->getStateUsing(function ($record) {
                        if (! $record) {
                            return '—';
                        }

                        $findOrder = function () use ($record) {
                            // 1 direct link via order_id
                            try {
                                if (\Illuminate\Support\Facades\Schema::hasColumn('appointments', 'order_id') && ! empty($record->order_id)) {
                                    $ord = \App\Models\Order::find($record->order_id);
                                    if ($ord) {
                                        return $ord;
                                    }
                                }
                            } catch (\Throwable $e) {
                            }

                            // 2 match by stored appointment time in order meta
                            try {
                                if (! empty($record->start_at)) {
                                    $s      = \Carbon\Carbon::parse($record->start_at);
                                    $isoUtc = $s->copy()->setTimezone('UTC')->toIso8601String();
                                    $ymsLon = $s->copy()->setTimezone('Europe/London')->format('Y-m-d H:i:s');

                                    foreach (['appointment_start_at', 'appointment_at'] as $key) {
                                        $ord = \App\Models\Order::query()
                                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.$key')) in (?,?)", [$isoUtc, $ymsLon])
                                            ->orderByDesc('id')
                                            ->first(['id', 'meta']);
                                        if ($ord) {
                                            return $ord;
                                        }
                                    }
                                }
                            } catch (\Throwable $e) {
                            }

                            return null;
                        };

                        $ord = $findOrder();
                        if (! $ord) {
                            $name = is_string($record->service_name ?? null) ? trim($record->service_name) : '';
                            if ($name === '') {
                                $name = is_string($record->service ?? null) ? trim($record->service) : '';
                            }
                            return $name !== '' ? $name : '—';
                        }

                        $meta = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);

                        $name = null;
                        $variation = null;
                        $qty = null;

                        $sel = data_get($meta, 'selectedProduct');
                        if (is_array($sel)) {
                            $name      = (string) ($sel['name'] ?? '');
                            $variation = (string) ($sel['variation'] ?? $sel['strength'] ?? '');
                            $qty       = isset($sel['qty']) ? (int) $sel['qty'] : $qty;
                        }

                        if (! $name) {
                            $line0 = data_get($meta, 'lines.0');
                            if (is_array($line0)) {
                                $name      = (string) ($line0['name'] ?? '');
                                $variation = (string) ($line0['variation'] ?? '');
                                $qty       = isset($line0['qty']) ? (int) $line0['qty'] : $qty;
                            }
                        }

                        if (! $name) {
                            $item0 = data_get($meta, 'items.0');
                            if (is_array($item0)) {
                                $name      = (string) ($item0['name'] ?? '');
                                $variation = (string) ($item0['variations'] ?? $item0['strength'] ?? '');
                                $qty       = isset($item0['qty']) ? (int) $item0['qty'] : $qty;
                            }
                        }

                        if (! $name) {
                            $name = (string) (data_get($meta, 'service') ?? '');
                        }

                        $name      = is_string($name) ? trim($name) : '';
                        $variation = is_string($variation) ? trim($variation) : '';
                        $qtySuffix = ' × ' . (($qty !== null && $qty > 0) ? $qty : 1);

                        if ($name === '' && $variation === '') {
                            return '—';
                        }
                        if ($name !== '' && $variation !== '') {
                            return $name . ' — ' . $variation . $qtySuffix;
                        }
                        return ($name !== '' ? $name : $variation) . $qtySuffix;
                    })
                    ->searchable(true, function (Builder $query, string $search): Builder {
                        $like = "%" . $search . "%";

                        return $query
                            ->whereExists(function ($sub) use ($like) {
                                $sub->select(\DB::raw('1'))
                                    ->from('orders')
                                    ->whereColumn('orders.id', 'appointments.order_id')
                                    ->where(function ($q) use ($like) {
                                        $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.selectedProduct.name')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.selectedProduct.title')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.selectedProduct.variation')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.selectedProduct.strength')) like ?", [$like])

                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.lines[0].name')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.lines[0].title')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.lines[0].variation')) like ?", [$like])

                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.items[0].name')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.items[0].title')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.items[0].variations')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.items[0].strength')) like ?", [$like])

                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.cart.items[0].name')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.cart.items[0].title')) like ?", [$like])

                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.product.name')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.product.title')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.product_name')) like ?", [$like])

                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.treatment')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.treatment.name')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.treatment_name')) like ?", [$like])

                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.medicine')) like ?", [$like])
                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.medicine.name')) like ?", [$like])

                                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.service')) like ?", [$like])

                                        ->orWhereRaw("CAST(orders.meta AS CHAR) like ?", [$like]);
                                    });
                            })
                            ->orWhere(function ($q) use ($like) {
                                $q->where('service_name', 'like', $like)
                                ->orWhere('service', 'like', $like)
                                ->orWhere('service_slug', 'like', $like);
                            });
                    })
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('patient')
                    ->label('Patient')
                    ->getStateUsing(function ($record) {
                        if (! $record) {
                            return '';
                        }

                        // 1) Explicit patient_name on the appointment
                        $pn = is_string($record->patient_name ?? null) ? trim($record->patient_name) : '';
                        if ($pn !== '') {
                            return $pn;
                        }

                        // 2) First / last name on the appointment row
                        $first = is_string($record->first_name ?? null) ? trim($record->first_name) : '';
                        $last  = is_string($record->last_name ?? null) ? trim($record->last_name) : '';
                        $name  = trim(trim($first . ' ' . $last));
                        if ($name !== '') {
                            return $name;
                        }

                        // 3) Linked order (using helper)
                        $order = static::findRelatedOrder($record);
                        if ($order) {
                            // 3a) Order's own first / last
                            $of = is_string($order->first_name ?? null) ? trim($order->first_name) : '';
                            $ol = is_string($order->last_name ?? null) ? trim($order->last_name) : '';
                            $on = trim(trim($of . ' ' . $ol));
                            if ($on !== '') {
                                return $on;
                            }

                            // 3b) Linked user on the order
                            $ou = optional($order->user);
                            $uf = is_string($ou->first_name ?? null) ? trim($ou->first_name) : '';
                            $ul = is_string($ou->last_name ?? null) ? trim($ou->last_name) : '';
                            $un = trim(trim($uf . ' ' . $ul));
                            if ($un !== '') {
                                return $un;
                            }
                            $un2 = is_string($ou->name ?? null) ? trim($ou->name) : '';
                            if ($un2 !== '') {
                                return $un2;
                            }

                            // 3c) Common shapes in order meta
                            $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

                            $metaName = data_get($meta, 'patient.name')
                                ?? data_get($meta, 'customer.name')
                                ?? data_get($meta, 'full_name')
                                ?? data_get($meta, 'name');

                            if (is_string($metaName) && trim($metaName) !== '') {
                                return trim($metaName);
                            }

                            $pf = data_get($meta, 'patient.first_name') ?? data_get($meta, 'customer.first_name');
                            $pl = data_get($meta, 'patient.last_name') ?? data_get($meta, 'customer.last_name');

                            if (is_string($pf) || is_string($pl)) {
                                $pf = is_string($pf) ? trim($pf) : '';
                                $pl = is_string($pl) ? trim($pl) : '';
                                $pfull = trim(trim($pf . ' ' . $pl));
                                if ($pfull !== '') {
                                    return $pfull;
                                }
                            }
                        }

                        // 4) Last resort: show nothing (do not show email as patient)
                        return '';
                    })
                    ->formatStateUsing(fn ($state) => (is_string($state) && trim($state) !== '') ? $state : '—')
                    ->searchable(true, function (Builder $query, string $search): Builder {
                        $like = '%' . $search . '%';

                        return $query->where(function (Builder $q) use ($like) {
                            $q->where('patient_name', 'like', $like)
                              ->orWhere('first_name', 'like', $like)
                              ->orWhere('last_name', 'like', $like)
                              ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", [$like])
                              ->orWhere('email', 'like', $like)
                              ->orWhereExists(function ($sub) use ($like) {
                                  $sub->select(\DB::raw('1'))
                                      ->from('orders')
                                      ->whereColumn('orders.id', 'appointments.order_id')
                                      ->where(function ($q2) use ($like) {
                                          $q2->orWhere('orders.first_name', 'like', $like)
                                             ->orWhere('orders.last_name', 'like', $like)
                                             ->orWhereRaw("concat_ws(' ', orders.first_name, orders.last_name) like ?", [$like])
                                             ->orWhere('orders.email', 'like', $like)
                                             ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.patient.name')) like ?", [$like])
                                             ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.patient.first_name')) like ?", [$like])
                                             ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.patient.last_name')) like ?", [$like])
                                             ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.customer.name')) like ?", [$like])
                                             ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.customer.first_name')) like ?", [$like])
                                             ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.customer.last_name')) like ?", [$like])
                                             ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.full_name')) like ?", [$like]);
                                      });
                              });
                        });
                    }),

                TextColumn::make('zoom_start')
                    ->label('Zoom')
                    ->alignCenter()
                    ->getStateUsing(fn ($record) => static::zoomHostUrlFor($record))
                    ->formatStateUsing(fn ($state) => filled($state) ? 'Start' : '—')
                    ->url(fn ($record) => static::zoomHostUrlFor($record))
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-video-camera')
                    ->color(fn ($state) => filled($state) ? 'success' : 'gray')
                    ->badge()
                    ->sortable(false),

                TextColumn::make('service')
                    ->label('Service')
                    ->getStateUsing(function ($record) {
                        if (! $record) {
                            return null;
                        }

                        $service = is_string($record->getRawOriginal('service') ?? null)
                            ? trim($record->getRawOriginal('service'))
                            : '';
                        $serviceSlug = is_string($record->getRawOriginal('service_slug') ?? null)
                            ? trim($record->getRawOriginal('service_slug'))
                            : '';

                        if ($service !== '') {
                            return $service;
                        }

                        if ($serviceSlug !== '') {
                            return $serviceSlug;
                        }

                        return null;
                    })
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return '—';
                        }

                        return \Illuminate\Support\Str::of((string) $state)
                            ->replace('-', ' ')
                            ->title()
                            ->toString();
                    })
                    ->toggleable()
                    ->searchable(true, function (Builder $query, string $search): Builder {
                        $like = "%" . $search . "%";

                        return $query->where(function ($q) use ($like) {
                            $q->where('service', 'like', $like)
                            ->orWhere('service_slug', 'like', $like)
                            ->orWhere('service_name', 'like', $like);
                        });
                    }),

            ])
            ->filters([
                Filter::make('day')
                    ->label('Date')
                    ->form([
                        DatePicker::make('on')->label('On')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $on = $data['on'] ?? null;

                        return $on ? $query->whereDate('start_at', $on) : $query;
                    })
                    ->indicateUsing(function ($state) {
                        $d = is_array($state) ? ($state['on'] ?? null) : null;

                        return $d ? ('Date ' . \Illuminate\Support\Carbon::parse($d)->format('d M Y')) : null;
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->button()
                    ->color('gray')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => static::orderDetailsUrlForRecord($record))
                    ->openUrlInNewTab(),

                \Filament\Actions\Action::make('reschedule')
                    ->label('Reschedule')
                    ->button()
                    ->color('warning')
                    ->icon('heroicon-o-calendar')
                    ->modalHeading('Reschedule appointment')
                    ->form([
                        // Hidden field that the action reads from ($data['start_at'])
                        \Filament\Forms\Components\Hidden::make('start_at')
                            ->required(),

                        // Same date picker behaviour as Create Appointment
                        DatePicker::make('start_date')
                            ->label('Date')
                            ->native(false)
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(function ($state, \Filament\Schemas\Components\Utilities\Set $set, $record) {
                                if (! $record || empty($record->start_at)) {
                                    return;
                                }
                                try {
                                    $dt = \Carbon\Carbon::parse($record->start_at)->tz('Europe/London');
                                    $set('start_date', $dt->format('Y-m-d'));
                                } catch (\Throwable $e) {
                                }
                            })
                            ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                                $date = $get('start_date');
                                $time = $get('start_time');
                                if (! $date || ! $time) {
                                    $set('start_at', null);
                                    return;
                                }
                                try {
                                    $dt = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $time, 'Europe/London');
                                    $set('start_at', $dt->format('Y-m-d H:i:s'));
                                } catch (\Throwable $e) {
                                    $set('start_at', null);
                                }
                            }),

                        // Same time picker options and validation as Create Appointment
                        Select::make('start_time')
                            ->label('Time')
                            ->native(false)
                            ->options(function () {
                                $opts = [];

                                // 08:00 to 18:00 inclusive in 15-minute steps
                                for ($h = 8; $h <= 18; $h++) {
                                    $hh = str_pad((string) $h, 2, '0', STR_PAD_LEFT);

                                    // only allow 18:00 (no 18:15 etc)
                                    if ($h === 18) {
                                        $t = $hh . ':00';
                                        $opts[$t] = $t;
                                        continue;
                                    }

                                    for ($m = 0; $m < 60; $m += 15) {
                                        $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
                                        $t = $hh . ':' . $mm;
                                        $opts[$t] = $t;
                                    }
                                }

                                return $opts;
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(function ($state, \Filament\Schemas\Components\Utilities\Set $set, $record) {
                                if (! $record || empty($record->start_at)) {
                                    return;
                                }
                                try {
                                    $dt = \Carbon\Carbon::parse($record->start_at)->tz('Europe/London');
                                    $t = $dt->format('H:i');

                                    [$hh, $mm] = array_pad(explode(':', $t, 2), 2, null);
                                    $h = is_numeric($hh) ? (int) $hh : -1;
                                    $m = is_numeric($mm) ? (int) $mm : -1;

                                    $ok = false;

                                    if ($h >= 8 && $h <= 17 && $m >= 0 && $m < 60 && ($m % 15 === 0)) {
                                        $ok = true;
                                    }

                                    if ($h === 18 && $m === 0) {
                                        $ok = true;
                                    }

                                    $set('start_time', $ok ? $t : null);
                                } catch (\Throwable $e) {
                                }
                            })
                            ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                                $date = $get('start_date');
                                $time = $get('start_time');
                                if (! $date || ! $time) {
                                    $set('start_at', null);
                                    return;
                                }
                                try {
                                    $dt = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $time, 'Europe/London');
                                    $set('start_at', $dt->format('Y-m-d H:i:s'));
                                } catch (\Throwable $e) {
                                    $set('start_at', null);
                                }
                            }),

                        Textarea::make('reason')
                            ->label('Reason for change')
                            ->rows(3),
                    ])
                    ->action(function (Appointment $record, array $data): void {
                        $oldStart = $record->start_at;
                        $oldEnd   = $record->end_at;

                        // 0) Resolve related order once using the OLD appointment time
                        //    so we do not lose the link when start_at changes.
                        $order = static::findRelatedOrder($record);

                        // 1) Update appointment start / end (default to 20 minutes if we don't have an existing duration)
                        $record->start_at = $data['start_at'];

                        if (! empty($data['end_at'])) {
                            $record->end_at = $data['end_at'];
                        } else {
                            try {
                                $duration = 20;

                                if ($oldEnd && $oldStart) {
                                    $oldStartDt = \Carbon\Carbon::parse($oldStart);
                                    $oldEndDt   = \Carbon\Carbon::parse($oldEnd);
                                    $duration   = max(1, $oldEndDt->diffInMinutes($oldStartDt));
                                }

                                $record->end_at = \Carbon\Carbon::parse($data['start_at'])->addMinutes($duration);
                            } catch (\Throwable $e) {
                                // If parsing fails, leave end_at as-is
                            }
                        }

                        $record->save();
                        $record->refresh(); // make sure "When" column updates

                        // 1.5) If we successfully found an order, hard-link it to the appointment
                        //      so we no longer rely on the heuristic start_at match.
                        if ($order) {
                            try {
                                if (\Illuminate\Support\Facades\Schema::hasColumn('appointments', 'order_id') && empty($record->order_id)) {
                                    $record->order_id = $order->getKey();
                                }
                            } catch (\Throwable $e) {
                                // ignore schema errors
                            }

                            $ref = is_string($record->order_reference ?? null) ? trim($record->order_reference) : '';
                            if ($ref === '' && is_string($order->reference ?? null) && trim($order->reference) !== '') {
                                $record->order_reference = trim($order->reference);
                            }

                            $record->save();
                            $record->refresh();
                        }

                        // 2) Optionally regenerate a Zoom link for weight management style services
                        $joinUrl = null;
                        if ($order && class_exists(\App\Services\ZoomMeetingService::class)) {
                            try {
                                // Work out the service slug from appointment fields
                                $serviceSlug = null;

                                if (is_string($record->service_slug ?? null) && trim($record->service_slug) !== '') {
                                    $serviceSlug = trim($record->service_slug);
                                } elseif (is_string($record->service ?? null) && trim($record->service) !== '') {
                                    $serviceSlug = \Illuminate\Support\Str::slug((string) $record->service);
                                } elseif (is_string($record->service_name ?? null) && trim($record->service_name) !== '') {
                                    $serviceSlug = \Illuminate\Support\Str::slug((string) $record->service_name);
                                }

                                $weightSlugs = ['weight-management', 'weight-loss', 'mounjaro', 'wegovy'];

                                if ($serviceSlug && in_array($serviceSlug, $weightSlugs, true)) {
                                    $zoom = app(\App\Services\ZoomMeetingService::class);
                                    $zoomInfo = $zoom->createForAppointment($record, $order);

                                    if ($zoomInfo && ! empty($zoomInfo['join_url'])) {
                                        $meta = is_array($order->meta)
                                            ? $order->meta
                                            : (json_decode($order->meta ?? '[]', true) ?: []);

                                        $meta['zoom'] = array_replace(
                                            $meta['zoom'] ?? [],
                                            [
                                                'meeting_id' => $zoomInfo['id'] ?? null,
                                                'join_url'   => $zoomInfo['join_url'] ?? null,
                                                'start_url'  => $zoomInfo['start_url'] ?? null,
                                            ]
                                        );

                                        $order->meta = $meta;
                                        $order->save();

                                        $joinUrl = (string) $zoomInfo['join_url'];

                                        \Log::info('appointment.zoom.rescheduled_saved', [
                                            'appointment' => $record->id,
                                            'order'       => $order->getKey(),
                                            'join_url'    => $joinUrl,
                                        ]);
                                    }
                                }
                            } catch (\Throwable $ze) {
                                \Log::warning('appointment.zoom.rescheduled_failed', [
                                    'appointment' => $record->id ?? null,
                                    'error'       => $ze->getMessage(),
                                ]);
                            }
                        }

                        // 3) Work out email address (appointment first, then order/meta/user)
                        $email = null;
                        if (is_string($record->email ?? null) && trim($record->email) !== '') {
                            $email = trim($record->email);
                        } elseif ($order) {
                            $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                            $email = data_get($meta, 'patient.email')
                                ?? data_get($meta, 'customer.email')
                                ?? $order->email
                                ?? optional($order->user)->email;
                            if (is_string($email)) {
                                $email = trim($email);
                            } else {
                                $email = null;
                            }
                        }

                        // 4) Send notification email if we have a target
                        if ($email) {
                            $whenOld = $oldStart
                                ? \Carbon\Carbon::parse($oldStart)->tz('Europe/London')->format('d M Y, H:i')
                                : 'your previous time';

                            $whenNew = $record->start_at
                                ? \Carbon\Carbon::parse($record->start_at)->tz('Europe/London')->format('d M Y, H:i')
                                : 'a new time';

                            $service = $record->service_name
                                ?? $record->service
                                ?? ($order ? (data_get(is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []), 'service') ?? '') : '');

                            $reason = trim($data['reason'] ?? '');

                            $subject = 'Your appointment has been rescheduled';

                            $lines = [];
                            $lines[] = 'Hello,';
                            $lines[] = '';
                            $lines[] = 'Your appointment' . ($service ? " for {$service}" : '') . ' has been rescheduled.';
                            $lines[] = "Previous time: {$whenOld}";
                            $lines[] = "New time: {$whenNew}";
                            if ($reason !== '') {
                                $lines[] = '';
                                $lines[] = 'Reason for change';
                                $lines[] = $reason;
                            }
                            if ($joinUrl) {
                                $lines[] = '';
                                $lines[] = 'Your new Zoom link';
                                $lines[] = $joinUrl;
                            }
                            $lines[] = '';
                            $lines[] = 'If this time is not suitable, please contact the pharmacy to rearrange.';

                            $body = implode("\n", $lines);

                            try {
                                $fromAddress = config('mail.from.address') ?: 'info@pharmacy-express.co.uk';
                                $fromName    = config('mail.from.name') ?: 'Pharmacy Express';

                                Mail::raw($body, function ($m) use ($email, $subject, $fromAddress, $fromName) {
                                    $m->from($fromAddress, $fromName)
                                        ->to($email)
                                        ->subject($subject);
                                });
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Appointment updated but email could not be sent')
                                    ->body(substr($e->getMessage(), 0, 200))
                                    ->send();
                                return;
                            }
                        }

                        Notification::make()
                            ->success()
                            ->title('Appointment rescheduled')
                            ->body('The appointment has been updated' . ($email ? ' and the patient has been notified at '.$email : '.'))
                            ->send();
                    }),

                \Filament\Actions\Action::make('delete')
                    ->label('Delete')
                    ->button()
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalHeading('Delete appointment')
                    ->modalDescription('This will permanently delete the appointment. This action cannot be undone.')
                    ->action(function (Appointment $record): void {
                        $record->delete();

                        Notification::make()
                            ->success()
                            ->title('Appointment deleted')
                            ->send();
                    }),
            ])
            ->defaultSort('start_at', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->recordUrl(null);
    }

    public static function getEloquentQuery(): Builder
    {
        $base = parent::getEloquentQuery();

        return $base
            // Only show appointments that have a scheduled time
            ->whereNotNull('start_at')
            // Treat "pending appointments" the same way as the navigation badge:
            // anything with a waiting / pending-style status.
            ->where(function (Builder $q) {
                $q->whereNull('status')
                  ->orWhere('status', '')
                  ->orWhere('status', 'waiting')
                  ->orWhere('status', 'pending');
            })
            ->orderBy('start_at', 'asc')
            ->orderByDesc('id');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Appointment::query()
            // Show badge for all upcoming waiting-type appointments from today onwards
            ->whereDate('start_at', '>=', now()->toDateString())
            ->where(function (Builder $q) {
                $q->whereNull('status')
                  ->orWhere('status', '')
                  ->orWhere('status', 'waiting')
                  ->orWhere('status', 'pending');
            })
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $hasWaiting = Appointment::query()
            ->whereDate('start_at', '>=', now()->toDateString())
            ->where(function (Builder $q) {
                $q->whereNull('status')
                  ->orWhere('status', '')
                  ->orWhere('status', 'waiting')
                  ->orWhere('status', 'pending');
            })
            ->exists();

        return $hasWaiting ? 'success' : 'gray';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting appointments (today and upcoming)';
    }
    
    public static function formatWhenFor(?\App\Models\Appointment $a): ?string
    {
        if (!$a) return null;
        $s = \Carbon\Carbon::parse($a->start_at)->tz('Europe/London');
        $e = $a->end_at ? \Carbon\Carbon::parse($a->end_at)->tz('Europe/London') : null;

        return $e && $s->isSameDay($e)
            ? $s->format('d M Y, H:i') . ' — ' . $e->format('H:i')
            : $s->format('d M Y, H:i') . ($e ? ' — ' . $e->format('d M Y, H:i') : '');
    }

    public static function getPages(): array
    {
        $pages = [
            'index' => ListAppointments::route('/'),
            'edit'  => EditAppointment::route('/{record}/edit'),
        ];

        // Add the Create page if it exists
        if (class_exists(Pages\CreateAppointment::class)) {
            $pages['create'] = Pages\CreateAppointment::route('/create');
        }

        // Add the View page only if it exists (prevents class-not-found issues)
        if (class_exists(ViewAppointment::class)) {
            $pages['view'] = ViewAppointment::route('/{record}');
        }

        return $pages;
    }
    // -- Helper methods for Ref/Item/URL placeholders and actions --
    // -- Helper methods for Ref/Item/URL placeholders and actions --

    public static function findRelatedOrder($record): ?\App\Models\Order
    {
        if (! $record) return null;

        // 1) Direct link first
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('appointments', 'order_id') && !empty($record->order_id)) {
                $o = \App\Models\Order::find($record->order_id);
                if ($o) return $o;
            }
        } catch (\Throwable $e) {}

        // 1.5) Match by reference value saved on the appointment row
        try {
            $ref = '';

            if (is_string($record->order_reference ?? null)) {
                $ref = trim((string) $record->order_reference);
            }

            // Admin created appointments often store the reference in `reference`, not `order_reference`
            if ($ref === '' && is_string($record->reference ?? null)) {
                $ref = trim((string) $record->reference);
            }

            if ($ref !== '') {
                $o = \App\Models\Order::query()
                    ->where('reference', $ref)
                    ->orderByDesc('id')
                    ->first();
                if ($o) {
                    return $o;
                }
            }
        } catch (\Throwable $e) {}

        // 2) Heuristic by matching appointment time stored in order meta
        try {
            if (!empty($record->start_at)) {
                $s = \Carbon\Carbon::parse($record->start_at);

                $utc = $s->copy()->setTimezone('UTC');
                $lon = $s->copy()->setTimezone('Europe/London');

                $candidates = array_values(array_unique(array_filter([
                    // ISO variants
                    $utc->toIso8601String(),
                    $lon->toIso8601String(),

                    // MySQL-ish variants
                    $utc->format('Y-m-d H:i:s'),
                    $lon->format('Y-m-d H:i:s'),
                    $utc->format('Y-m-d H:i'),
                    $lon->format('Y-m-d H:i'),

                    // Explicit Zulu format (often used in JS)
                    $utc->format('Y-m-d\\TH:i:s\\Z'),
                ], fn ($v) => is_string($v) && trim($v) !== '')));

                $keysToTry = [
                    'appointment_start_at',
                    'appointment_at',
                    'appointment_start',
                    'appointment_datetime',
                    'appointmentDateTime',
                ];

                foreach ($keysToTry as $key) {
                    $placeholders = implode(',', array_fill(0, count($candidates), '?'));

                    $ord = \App\Models\Order::query()
                        ->whereRaw(
                            "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.$key')) in ($placeholders)",
                            $candidates
                        )
                        ->orderByDesc('id')
                        ->first();

                    if ($ord) {
                        return $ord;
                    }
                }
            }
        } catch (\Throwable $e) {}

        return null;
    }

    protected static function resolveOrderRef($record): ?string
    {
        // 1) Use linked order ref if available
        $o = static::findRelatedOrder($record);
        if ($o) {
            $ref = $o->reference ?? $o->ref ?? null;
            if (is_string($ref)) {
                $ref = trim($ref);
                if ($ref !== '' && $ref !== '-') {
                    return $ref;
                }
            }
        }

        // 2) Fall back to appointment's own saved reference
        foreach (['order_reference', 'reference'] as $field) {
            $own = $record->{$field} ?? null;
            if (is_string($own)) {
                $own = trim($own);
                if ($own !== '' && $own !== '-') {
                    return $own;
                }
            }
        }

        return null;
    }

    protected static function resolveOrderItem($record): ?string
    {
        $o = static::findRelatedOrder($record);
        if (! $o) return null;
        $meta = is_array($o->meta) ? $o->meta : (json_decode($o->meta ?? '[]', true) ?: []);

        $name = null; $variation = null; $qty = null;

        if (is_array($sel = data_get($meta, 'selectedProduct'))) {
            $name = (string) ($sel['name'] ?? '');
            $variation = (string) ($sel['variation'] ?? $sel['strength'] ?? '');
            $qty = isset($sel['qty']) ? (int) $sel['qty'] : $qty;
        }
        if (! $name) {
            $line0 = data_get($meta, 'lines.0');
            if (is_array($line0)) {
                $name = (string) ($line0['name'] ?? '');
                $variation = (string) ($line0['variation'] ?? '');
                $qty = isset($line0['qty']) ? (int) $line0['qty'] : $qty;
            }
        }
        if (! $name) {
            $item0 = data_get($meta, 'items.0');
            if (is_array($item0)) {
                $name = (string) ($item0['name'] ?? '');
                $variation = (string) ($item0['variations'] ?? $item0['strength'] ?? '');
                $qty = isset($item0['qty']) ? (int) $item0['qty'] : $qty;
            }
        }
        if (! $name) {
            $name = (string) (data_get($meta,'service') ?? '');
        }

        $name = is_string($name) ? trim($name) : '';
        $variation = is_string($variation) ? trim($variation) : '';
        $qtySuffix = ' × ' . (($qty !== null && $qty > 0) ? $qty : 1);

        if ($name === '' && $variation === '') return null;
        if ($name !== '' && $variation !== '') return $name.' — '.$variation.$qtySuffix;
        return ($name !== '' ? $name : $variation).$qtySuffix;
    }

    protected static function orderDetailsUrlForRecord($record): string
    {
        $order = static::findRelatedOrder($record);
        if (! $order) {
            return static::getUrl('edit', ['record' => $record]);
        }

        // Normalise pending state for both appointment and order
        $apptStatus  = is_string($record->status ?? null) ? strtolower(trim($record->status)) : null;
        $orderStatus = is_string($order->status ?? null) ? strtolower(trim($order->status)) : null;
        $payStatus   = is_string($order->payment_status ?? null) ? strtolower(trim($order->payment_status)) : null;

        $isPendingAppt = $apptStatus === 'pending';
        $isPendingOrd  = ($orderStatus === 'pending') || ($payStatus === 'pending');

        if ($isPendingAppt || $isPendingOrd) {
            // If this is an NHS flow, send them to NHS Pending instead of Private Pending.
            $orderMeta = null;
            try {
                $orderMeta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
            } catch (\Throwable $e) {
                $orderMeta = [];
            }

            $typeRaw = strtolower(trim((string) (
                data_get($orderMeta, 'type')
                ?? data_get($orderMeta, 'mode')
                ?? data_get($orderMeta, 'flow')
                ?? data_get($orderMeta, 'service.type')
                ?? ($order->type ?? '')
            )));

            $pathRaw = strtolower(trim((string) (
                data_get($orderMeta, 'path')
                ?? data_get($orderMeta, 'source_url')
                ?? data_get($orderMeta, 'referer')
                ?? data_get($orderMeta, 'source')
                ?? ''
            )));

            $isNhs = in_array($typeRaw, ['nhs', 'pharmacy-first', 'pharmacy first', 'nhs-first'], true)
                || str_contains($pathRaw, '/nhs-services')
                || str_contains($pathRaw, '/nhs');

            if ($isNhs) {
                return url('/admin/nhs-pending/nhs-pendings');
            }

            return url('/admin/pending-orders');
        }

        // Keep the original completed details path which you confirmed is correct
        return url("/admin/orders/completed-orders/{$order->id}/details");
    }

    protected static function zoomHostUrlFor($record): ?string
    {
        try {
            if (! $record) {
                return null;
            }

            // 1) Direct scalar fields on the appointment
            foreach (['zoom_start_url', 'zoom_host_url', 'host_zoom_url', 'zoom_url', 'zoom_start', 'zoom_host', 'zoom_link'] as $k) {
                try {
                    if (isset($record->{$k}) && is_string($record->{$k}) && trim($record->{$k}) !== '') {
                        return trim($record->{$k});
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // 1b) Common appointment-level JSON / array shapes (casts) like `zoom`, `zoom_info`, `zoomInfo`
            foreach (['zoom', 'zoom_info', 'zoomInfo', 'zoom_meeting', 'meeting', 'video'] as $k) {
                try {
                    if (! isset($record->{$k})) {
                        continue;
                    }

                    $val = $record->{$k};

                    if (is_string($val)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $val = $decoded;
                        }
                    }

                    if (is_array($val)) {
                        $url = $val['start_url'] ?? $val['host_url'] ?? $val['startUrl'] ?? $val['hostUrl'] ?? null;
                        if (is_string($url) && trim($url) !== '') {
                            return trim($url);
                        }

                        $join = $val['join_url'] ?? $val['joinUrl'] ?? null;
                        if (is_string($join) && trim($join) !== '') {
                            return trim($join);
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // 1c) Appointment meta.zoom.* (this is now the primary source for admin-created appointments)
            try {
                if (isset($record->meta)) {
                    $ameta = $record->meta;

                    if (is_string($ameta)) {
                        $decoded = json_decode($ameta, true);
                        if (is_array($decoded)) {
                            $ameta = $decoded;
                        }
                    }

                    if (is_array($ameta)) {
                        $url = Arr::get($ameta, 'zoom.start_url')
                            ?? Arr::get($ameta, 'zoom.host_url')
                            ?? Arr::get($ameta, 'zoom.startUrl')
                            ?? Arr::get($ameta, 'zoom.hostUrl');

                        if (is_string($url) && trim($url) !== '') {
                            return trim($url);
                        }

                        $join = Arr::get($ameta, 'zoom.join_url')
                            ?? Arr::get($ameta, 'zoom.joinUrl');

                        if (is_string($join) && trim($join) !== '') {
                            return trim($join);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }

            // 2) Try to resolve a related Order first, then a PendingOrder (admin-created appointments often map to pending_orders)
            $orderLike = static::findRelatedOrder($record);
            if (! $orderLike) {
                $orderLike = static::findRelatedPendingOrder($record);
            }

            // 3) Extract from meta / zoomInfo shapes
            $meta = null;
            $zoomInfo = null;

            if ($orderLike) {
                $meta = $orderLike->meta ?? null;
                $zoomInfo = $orderLike->zoomInfo ?? $orderLike->zoom_info ?? null;
            }

            // Appointment may also have its own meta fields
            foreach (['meta', 'metadata', 'data', 'payload'] as $mk) {
                try {
                    if ($meta === null && isset($record->{$mk})) {
                        $meta = $record->{$mk};
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // Normalise meta
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            if (is_string($zoomInfo)) {
                $decoded = json_decode($zoomInfo, true);
                if (is_array($decoded)) {
                    $zoomInfo = $decoded;
                }
            }

            if (is_array($meta)) {
                $url =
                    Arr::get($meta, 'zoomInfo.start_url') ??
                    Arr::get($meta, 'zoomInfo.host_url') ??
                    Arr::get($meta, 'zoom_info.start_url') ??
                    Arr::get($meta, 'zoom_info.host_url') ??
                    Arr::get($meta, 'zoom.start_url') ??
                    Arr::get($meta, 'zoom.host_url') ??
                    Arr::get($meta, 'zoom.startUrl') ??
                    Arr::get($meta, 'zoom.hostUrl') ??
                    Arr::get($meta, 'zoom_start_url') ??
                    Arr::get($meta, 'zoom_host_url');

                if (! empty($url) && is_string($url)) {
                    return trim($url);
                }

                // Sometimes only join_url is stored – do not prefer it, but use as last resort so the button appears.
                $join =
                    Arr::get($meta, 'zoomInfo.join_url') ??
                    Arr::get($meta, 'zoom_info.join_url') ??
                    Arr::get($meta, 'zoom.join_url') ??
                    Arr::get($meta, 'zoom.joinUrl') ??
                    Arr::get($meta, 'zoom_join_url');

                if (! empty($join) && is_string($join)) {
                    return trim($join);
                }

                // Fallback: recursively scan meta for ANY Zoom URL, even if stored under unknown keys
                $candidates = [];
                $walk = function ($v) use (&$walk, &$candidates) {
                    if (is_string($v)) {
                        $s = trim($v);
                        if ($s !== '' && str_contains($s, 'zoom.us')) {
                            $candidates[] = $s;
                        }
                        return;
                    }
                    if (is_array($v)) {
                        foreach ($v as $vv) {
                            $walk($vv);
                        }
                    }
                };

                $walk($meta);

                if (! empty($candidates)) {
                    // Prefer host/start URLs (they often contain zak= or start)
                    foreach ($candidates as $u) {
                        if (str_contains($u, 'zak=') || str_contains($u, 'start')) {
                            return $u;
                        }
                    }
                    return $candidates[0];
                }
            }

            if (is_array($zoomInfo)) {
                $url = Arr::get($zoomInfo, 'start_url') ?? Arr::get($zoomInfo, 'host_url') ?? Arr::get($zoomInfo, 'startUrl') ?? Arr::get($zoomInfo, 'hostUrl');
                if (! empty($url) && is_string($url)) {
                    return trim($url);
                }

                $join = Arr::get($zoomInfo, 'join_url') ?? Arr::get($zoomInfo, 'joinUrl');
                if (! empty($join) && is_string($join)) {
                    return trim($join);
                }

                // Fallback scan within zoomInfo for any Zoom URL
                $candidates = [];
                $walk = function ($v) use (&$walk, &$candidates) {
                    if (is_string($v)) {
                        $s = trim($v);
                        if ($s !== '' && str_contains($s, 'zoom.us')) {
                            $candidates[] = $s;
                        }
                        return;
                    }
                    if (is_array($v)) {
                        foreach ($v as $vv) {
                            $walk($vv);
                        }
                    }
                };
                $walk($zoomInfo);

                if (! empty($candidates)) {
                    foreach ($candidates as $u) {
                        if (str_contains($u, 'zak=') || str_contains($u, 'start')) {
                            return $u;
                        }
                    }
                    return $candidates[0];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    // --- Custom helpers for PCAO reference and Zoom storage ---
    public static function generatePcaoRef(): string
    {
        try {
            $rand = random_int(0, 999999);
        } catch (\Throwable $e) {
            $rand = mt_rand(0, 999999);
        }

        return 'PCAO' . str_pad((string) $rand, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Ensure there is a stored Zoom host URL that the table column can read.
     *
     * For admin-created appointments, there may be no Order row yet. In that case,
     * we create/update a matching PendingOrder by reference and store `meta.zoom.start_url`.
     */
    public static function ensureZoomStoredForAppointment(\App\Models\Appointment $record): void
    {
        if (! $record) {
            return;
        }

        // Only for online consultations.
        if (! (bool) ($record->online_consultation ?? false)) {
            return;
        }

        // If we already have a URL that would show in the column, do nothing.
        try {
            if (filled(static::zoomHostUrlFor($record))) {
                return;
            }
        } catch (\Throwable $e) {
        }

        if (! class_exists(\App\Services\ZoomMeetingService::class)) {
            return;
        }

        $realOrder = null;
        try {
            $realOrder = static::findRelatedOrder($record);
        } catch (\Throwable $e) {
        }

        // Create a lightweight Order model if we don't have a real one yet.
        $orderForZoom = $realOrder ?: new \App\Models\Order();

        if (! $realOrder) {
            $ref = static::resolveOrderRef($record)
                ?? (is_string($record->order_reference ?? null) ? trim((string) $record->order_reference) : '');

            if ($ref !== '') {
                $orderForZoom->reference = $ref;
            }

            if (is_string($record->first_name ?? null)) {
                $orderForZoom->first_name = $record->first_name;
            }

            if (is_string($record->last_name ?? null)) {
                $orderForZoom->last_name = $record->last_name;
            }

            if (is_string($record->email ?? null)) {
                $orderForZoom->email = $record->email;
            }

            $orderForZoom->meta = [
                'service'         => $record->service_name ?? $record->service ?? null,
                'appointment_at'  => $record->start_at ? \Carbon\Carbon::parse($record->start_at)->toIso8601String() : null,
                'patient'         => [
                    'first_name' => $record->first_name ?? null,
                    'last_name'  => $record->last_name ?? null,
                    'email'      => $record->email ?? null,
                ],
            ];
        }

        try {
            $zoom = app(\App\Services\ZoomMeetingService::class);
            $zoomInfo = $zoom->createForAppointment($record, $orderForZoom);

            if (! is_array($zoomInfo)) {
                return;
            }

            $startUrl = $zoomInfo['start_url'] ?? $zoomInfo['startUrl'] ?? null;
            $joinUrl  = $zoomInfo['join_url'] ?? $zoomInfo['joinUrl'] ?? null;

            if (! is_string($startUrl) || trim($startUrl) === '') {
                return;
            }

            $payload = [
                'meeting_id' => $zoomInfo['id'] ?? $zoomInfo['meeting_id'] ?? null,
                'join_url'   => is_string($joinUrl) ? $joinUrl : null,
                'start_url'  => $startUrl,
                'saved_at'   => now()->toIso8601String(),
            ];

            // If we have a real order, store it there.
            if ($realOrder) {
                $meta = is_array($realOrder->meta) ? $realOrder->meta : (json_decode($realOrder->meta ?? '[]', true) ?: []);
                $meta['zoom'] = array_replace($meta['zoom'] ?? [], $payload);
                $realOrder->meta = $meta;
                $realOrder->save();
                return;
            }

            // Otherwise, create/update a PendingOrder so the Appointment table column can resolve the host URL.
            $ref = static::resolveOrderRef($record)
                ?? (is_string($record->order_reference ?? null) ? trim((string) $record->order_reference) : '');

            if ($ref === '') {
                return;
            }

            try {
                $pending = \App\Models\PendingOrder::query()->where('reference', $ref)->latest('id')->first();

                if (! $pending) {
                    $pending = new \App\Models\PendingOrder();
                    $pending->reference = $ref;
                }

                $pMeta = is_array($pending->meta) ? $pending->meta : (json_decode($pending->meta ?? '[]', true) ?: []);

                // Keep a few useful bits for debugging/traceability.
                $pMeta['appointment_at'] = $pMeta['appointment_at'] ?? ($record->start_at ? \Carbon\Carbon::parse($record->start_at)->toIso8601String() : null);
                $pMeta['service']        = $pMeta['service'] ?? ($record->service_name ?? $record->service ?? null);
                $pMeta['patient']        = $pMeta['patient'] ?? [
                    'first_name' => $record->first_name ?? null,
                    'last_name'  => $record->last_name ?? null,
                    'email'      => $record->email ?? null,
                ];

                $pMeta['zoom'] = array_replace($pMeta['zoom'] ?? [], $payload);

                $pending->meta = $pMeta;
                $pending->save();
            } catch (\Throwable $pe) {
                \Log::warning('appointment.zoom.pending_save_failed', [
                    'ref'   => $ref,
                    'appt'  => $record->id ?? null,
                    'error' => $pe->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('appointment.zoom.ensure_failed', [
                'appt'  => $record->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // --- End custom helpers ---

    protected static function findRelatedPendingOrder($record): ?\App\Models\PendingOrder
    {
        if (! $record) {
            return null;
        }

        // 1) Match by a reference value saved on the appointment row
        try {
            $ref = '';

            if (is_string($record->order_reference ?? null)) {
                $ref = trim((string) $record->order_reference);
            }

            // Admin created appointments often store the reference in `reference`
            if ($ref === '' && is_string($record->reference ?? null)) {
                $ref = trim((string) $record->reference);
            }

            if ($ref !== '') {
                $p = \App\Models\PendingOrder::query()
                    ->where('reference', $ref)
                    ->orderByDesc('id')
                    ->first();
                if ($p) {
                    return $p;
                }
            }
        } catch (\Throwable $e) {
        }

        // 2) Heuristic by matching appointment time stored in pending order meta
        try {
            if (! empty($record->start_at)) {
                $s = \Carbon\Carbon::parse($record->start_at);

                $utc = $s->copy()->setTimezone('UTC');
                $lon = $s->copy()->setTimezone('Europe/London');

                $candidates = array_values(array_unique(array_filter([
                    $utc->toIso8601String(),
                    $lon->toIso8601String(),
                    $utc->format('Y-m-d H:i:s'),
                    $lon->format('Y-m-d H:i:s'),
                    $utc->format('Y-m-d H:i'),
                    $lon->format('Y-m-d H:i'),
                    $utc->format('Y-m-d\\TH:i:s\\Z'),
                ], fn ($v) => is_string($v) && trim($v) !== '')));

                $keysToTry = [
                    'appointment_start_at',
                    'appointment_at',
                    'appointment_start',
                    'appointment_datetime',
                    'appointmentDateTime',
                ];

                foreach ($keysToTry as $key) {
                    $placeholders = implode(',', array_fill(0, count($candidates), '?'));

                    $p = \App\Models\PendingOrder::query()
                        ->whereRaw(
                            "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.$key')) in ($placeholders)",
                            $candidates
                        )
                        ->orderByDesc('id')
                        ->first();

                    if ($p) {
                        return $p;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }
}