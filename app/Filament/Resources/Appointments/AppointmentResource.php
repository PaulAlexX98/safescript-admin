<?php

namespace App\Filament\Resources\Appointments;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use App\Filament\Resources\Appointments\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\Appointments\AppointmentResource\Pages\EditAppointment;
use App\Filament\Resources\Appointments\AppointmentResource\Pages\ViewAppointment;
use App\Filament\Resources\Appointments\AppointmentResource\Pages;
use App\Models\Appointment;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\TextInput;
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

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    // Sidebar placement
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Appointments';
    protected static string | \UnitEnum | null $navigationGroup = 'Notifications';
    protected static ?int    $navigationSort  = 1;

    // Title used on View/Edit pages
    protected static ?string $recordTitleAttribute = 'display_title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('start_at')->label('Start')->required()->helperText('Format YYYY-MM-DD HH:MM'),
            TextInput::make('end_at')->label('End')->helperText('Format YYYY-MM-DD HH:MM'),
            TextInput::make('patient_name')->label('Patient name'),
            TextInput::make('first_name')->maxLength(120),
            TextInput::make('last_name')->maxLength(120),
            TextInput::make('service_name')->label('Service'),
            TextInput::make('service_slug')->label('Service key'),
            TextInput::make('status')->default('booked')->maxLength(50),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('start_at')->label('When')->dateTime('d M Y, H:i')->sortable()->searchable(),
                TextColumn::make('reference')
                    ->label('Ref')
                    ->getStateUsing(function ($record) {
                        if (!$record) return '—';
                        try {
                            // 1) Direct link via order_id if present
                            if (\Illuminate\Support\Facades\Schema::hasColumn('appointments','order_id') && !empty($record->order_id)) {
                                $ord = \App\Models\Order::find($record->order_id);
                                if ($ord) {
                                    $ref = is_string($ord->reference ?? null) ? trim($ord->reference) : '';
                                    if ($ref !== '') return $ref;
                                }
                            }

                            // 2) Match by stored appointment time in order meta
                            if (!empty($record->start_at)) {
                                try {
                                    $s = \Carbon\Carbon::parse($record->start_at);
                                    $isoUtc = $s->copy()->setTimezone('UTC')->toIso8601String();
                                    $ymsLon = $s->copy()->setTimezone('Europe/London')->format('Y-m-d H:i:s');

                                    foreach (['appointment_start_at','appointment_at'] as $key) {
                                        $ref = \App\Models\Order::query()
                                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.$key')) in (?,?)", [$isoUtc, $ymsLon])
                                            ->orderByDesc('id')
                                            ->value('reference');
                                        if (is_string($ref) && trim($ref) !== '') return trim($ref);
                                    }
                                } catch (\Throwable $e) { /* ignore parse issues */ }
                            }
                        } catch (\Throwable $e) { /* ignore */ }
                        return '—';
                    })
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('order_item')
                    ->label('Item')
                    ->getStateUsing(function ($record) {
                        if (!$record) return '—';

                        $findOrder = function () use ($record) {
                            // 1 direct link via order_id
                            try {
                                if (\Illuminate\Support\Facades\Schema::hasColumn('appointments','order_id') && !empty($record->order_id)) {
                                    $ord = \App\Models\Order::find($record->order_id);
                                    if ($ord) return $ord;
                                }
                            } catch (\Throwable $e) {}

                            // 2 match by stored appointment time in order meta
                            try {
                                if (!empty($record->start_at)) {
                                    $s = \Carbon\Carbon::parse($record->start_at);
                                    $isoUtc = $s->copy()->setTimezone('UTC')->toIso8601String();
                                    $ymsLon = $s->copy()->setTimezone('Europe/London')->format('Y-m-d H:i:s');

                                    foreach (['appointment_start_at','appointment_at'] as $key) {
                                        $ord = \App\Models\Order::query()
                                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.$key')) in (?,?)", [$isoUtc, $ymsLon])
                                            ->orderByDesc('id')
                                            ->first(['id','meta']);
                                        if ($ord) return $ord;
                                    }
                                }
                            } catch (\Throwable $e) {}

                            return null;
                        };

                        $ord = $findOrder();
                        if (!$ord) return '—';

                        $meta = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);

                        // Extract the first item name variation from common shapes
                        $name = null; $variation = null; $qty = null;

                        $sel = data_get($meta, 'selectedProduct');
                        if (is_array($sel)) {
                            $name = (string) ($sel['name'] ?? '');
                            $variation = (string) ($sel['variation'] ?? $sel['strength'] ?? '');
                            $qty = isset($sel['qty']) ? (int) $sel['qty'] : $qty;
                        }

                        if (!$name) {
                            $line0 = data_get($meta, 'lines.0');
                            if (is_array($line0)) {
                                $name = (string) ($line0['name'] ?? '');
                                $variation = (string) ($line0['variation'] ?? '');
                                $qty = isset($line0['qty']) ? (int) $line0['qty'] : $qty;
                            }
                        }

                        if (!$name) {
                            $item0 = data_get($meta, 'items.0');
                            if (is_array($item0)) {
                                $name = (string) ($item0['name'] ?? '');
                                $variation = (string) ($item0['variations'] ?? $item0['strength'] ?? '');
                                $qty = isset($item0['qty']) ? (int) $item0['qty'] : $qty;
                            }
                        }

                        if (!$name) {
                            $name = (string) (data_get($meta,'service') ?? '');
                        }

                        $name = is_string($name) ? trim($name) : '';
                        $variation = is_string($variation) ? trim($variation) : '';
                        $qtySuffix = ' × ' . (($qty !== null && $qty > 0) ? $qty : 1);

                        if ($name === '' && $variation === '') return '—';
                        if ($name !== '' && $variation !== '') return $name.' — '.$variation.$qtySuffix;
                        return ($name !== '' ? $name : $variation).$qtySuffix;
                    })
                    ->wrap()
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('patient')
                    ->label('Patient')
                    ->getStateUsing(function ($record) {
                        if (!$record) return '—';

                        // 1) Prefer explicit patient_name on the appointment
                        $pn = is_string($record->patient_name ?? null) ? trim($record->patient_name) : '';
                        if ($pn !== '') return $pn;

                        // 2) Try first_name / last_name on the appointment row
                        $first = is_string($record->first_name ?? null) ? trim($record->first_name) : '';
                        $last  = is_string($record->last_name ?? null) ? trim($record->last_name) : '';
                        $name  = trim(trim($first.' '.$last));
                        if ($name !== '') return $name;

                        // Helper to extract a display name from an order/meta shape
                        $extractName = function ($meta, $order = null) {
                            $firstKeys = [
                                'first_name','firstName','first','given_name','givenName',
                                'patient.first_name','patient.firstName','patient.first','patient.given_name','patient.givenName',
                                'customer.first_name','customer.firstName','customer.first','customer.given_name','customer.givenName',
                                'billing_first_name','shipping_first_name',
                            ];
                            $lastKeys  = [
                                'last_name','lastName','last','family_name','familyName','surname',
                                'patient.last_name','patient.lastName','patient.last','patient.family_name','patient.familyName',
                                'customer.last_name','customer.lastName','customer.last','customer.family_name','customer.familyName',
                                'billing_last_name','shipping_last_name',
                            ];
                            $singleKeys = [
                                'patient.full_name','customer.full_name','full_name',
                                'patient.name','customer.name','name','billing_name','shipping_name',
                            ];

                            // Try order columns if provided
                            if ($order) {
                                $of = is_string($order->first_name ?? null) ? trim($order->first_name) : '';
                                $ol = is_string($order->last_name  ?? null) ? trim($order->last_name)  : '';
                                $on = trim(trim($of.' '.$ol));
                                if ($on !== '') return $on;
                            }

                            $f = null; $l = null;
                            foreach ($firstKeys as $k) { $v = data_get($meta, $k); if (is_string($v) && trim($v) !== '') { $f = trim($v); break; } }
                            foreach ($lastKeys  as $k) { $v = data_get($meta, $k); if (is_string($v) && trim($v) !== '') { $l = trim($v); break; } }

                            $joined = trim(trim((string)$f).' '.trim((string)$l));
                            if ($joined !== '') return $joined;

                            foreach ($singleKeys as $k) {
                                $v = data_get($meta, $k);
                                if (is_string($v) && trim($v) !== '') return trim($v);
                            }
                            return null;
                        };

                        // 2.5) Directly linked order via order_id if present
                        try {
                            if (\Illuminate\Support\Facades\Schema::hasColumn('appointments','order_id') && !empty($record->order_id)) {
                                $ord = \App\Models\Order::find($record->order_id);
                                if ($ord) {
                                    $meta = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                                    if ($n = $extractName($meta, $ord)) return $n;

                                    // also try the linked user on the order
                                    $uf = is_string(optional($ord->user)->first_name ?? null) ? trim($ord->user->first_name) : '';
                                    $ul = is_string(optional($ord->user)->last_name  ?? null) ? trim($ord->user->last_name)  : '';
                                    $un = trim(trim($uf.' '.$ul));
                                    if ($un !== '') return $un;
                                    $un2 = is_string(optional($ord->user)->name ?? null) ? trim($ord->user->name) : '';
                                    if ($un2 !== '') return $un2;
                                }
                            }
                        } catch (\Throwable $e) {}

                        // 3) Fall back to related user's first/last or name (if appointment->user relation exists)
                        $ufirst = is_string(optional($record->user)->first_name ?? null) ? trim(optional($record->user)->first_name) : '';
                        $ulast  = is_string(optional($record->user)->last_name  ?? null) ? trim(optional($record->user)->last_name)  : '';
                        $uname  = trim(trim($ufirst.' '.$ulast));
                        if ($uname !== '') return $uname;
                        $uname2 = is_string(optional($record->user)->name ?? null) ? trim(optional($record->user)->name) : '';
                        if ($uname2 !== '') return $uname2;

                        // 4) Heuristic: look for a matching Order on the same day (prefer same service)
                        try {
                            $day = \Illuminate\Support\Carbon::parse($record->start_at)->toDateString();
                            $svcAppt = \Illuminate\Support\Str::slug((string) ($record->service_slug ?? $record->service ?? $record->service_name ?? ''));

                            $orders = \App\Models\Order::query()
                                ->whereDate('created_at', $day)
                                ->orderByDesc('created_at')
                                ->limit(15)
                                ->get(['id','meta','user_id','first_name','last_name','created_at']);

                            foreach ($orders as $ord) {
                                $meta = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);

                                // align services if both sides have something
                                $svcMeta = \Illuminate\Support\Str::slug((string) (data_get($meta,'service_slug') ?? data_get($meta,'service') ?? ''));
                                if ($svcAppt && $svcMeta && $svcMeta !== $svcAppt) continue;

                                if ($n = $extractName($meta, $ord)) return $n;

                                // final try with the order's user
                                $ou = optional($ord->user);
                                $uf = is_string($ou->first_name ?? null) ? trim($ou->first_name) : '';
                                $ul = is_string($ou->last_name  ?? null) ? trim($ou->last_name)  : '';
                                $un = trim(trim($uf.' '.$ul));
                                if ($un !== '') return $un;
                                $un2 = is_string($ou->name ?? null) ? trim($ou->name) : '';
                                if ($un2 !== '') return $un2;
                            }
                        } catch (\Throwable $e) {
                            // ignore lookup errors
                        }

                        // 5) Last resort: show appointment email
                        $email = is_string($record->email ?? null) ? trim($record->email) : '';
                        return $email !== '' ? $email : '—';
                    })
                    ->searchable(),
                TextColumn::make('service')
                    ->label('Service')
                    ->formatStateUsing(fn ($state) => $state ? Str::of($state)->replace('-', ' ')->title() : '—')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'booked', 'approved' => 'success',
                        'pending'            => 'warning',
                        'cancelled', 'canceled' => 'gray',
                        'rejected'           => 'danger',
                        default              => 'primary',
                    })
                    ->sortable(),
                TextColumn::make('deleted_at')->label('Deleted')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Day selector (defaults to today) — shows only that date
                SelectFilter::make('day')
                    ->label('Day')
                    ->options(function () {
                        $options = [];
                        for ($i = -180; $i <= 180; $i++) {
                            $date = now()->clone()->addDays($i);
                            $options[$date->toDateString()] = $date->format('D d M');
                        }
                        return $options;
                    })
                    ->default(now()->toDateString())
                    ->query(function (Builder $query, array $data): Builder {
                        // $data comes as ['value' => 'YYYY-MM-DD']
                        $date = $data['value'] ?? null;
                        if ($date) {
                            $query->whereDate('start_at', $date);
                        }
                        return $query;
                    })
                    ->indicateUsing(function ($state) {
                        $date = is_array($state) ? ($state['value'] ?? null) : $state;
                        return $date ? 'Day: ' . \Illuminate\Support\Carbon::parse($date)->format('D d M') : null;
                    }),

                // Status filter (inserted after Day)
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        'booked'    => 'Booked',
                        'approved'  => 'Approved',
                        'pending'   => 'Pending',
                        'cancelled' => 'Cancelled',
                        'rejected'  => 'Rejected',
                    ])
                    ->default(['booked', 'approved'])
                    ->query(function (Builder $query, array $data): Builder {
                        // $data may be ['values' => [...]] for multiple select
                        $values = $data['values'] ?? [];
                        $values = array_values(array_filter(array_map('strtolower', (array) $values)));
                        if (!empty($values)) {
                            $query->whereIn('status', $values);
                        }
                        return $query;
                    }),

                // Optional: Upcoming toggle (no default). Use if you only want future times for the chosen day.
                Filter::make('upcoming')
                    ->label('Upcoming')
                    ->query(fn (Builder $q) => $q->where('start_at', '>=', now())),
            ])
            ->defaultSort('start_at', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->recordUrl(fn ($record) => static::getUrl('edit', ['record' => $record]));
    }

    // Allow the TrashedFilter to work (include trashed in base query)
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        // Count appointments for *today* (entire day), ignoring pending.
        $count = Appointment::query()
            ->whereDate('start_at', now()->toDateString())
            ->where('status', '!=', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        // Green if there are appointments today, gray if none.
        $count = Appointment::query()
            ->whereDate('start_at', now()->toDateString())
            ->count();

        return $count > 0 ? 'success' : 'gray';
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

        // Add the View page only if it exists (prevents class-not-found issues)
        if (class_exists(ViewAppointment::class)) {
            $pages['view'] = ViewAppointment::route('/{record}');
        }

        return $pages;
    }
}