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
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use App\Models\Order;
use App\Models\PendingOrder;
use Illuminate\Support\Arr;

class AppointmentResource extends Resource
{
    protected static function sixMonthReviewUserIdForAppointment($record): int
    {
        if (! $record) {
            return 0;
        }

        $directUserId = (int) ($record->user_id ?? optional($record->user ?? null)->id ?? 0);
        if ($directUserId > 0) {
            return $directUserId;
        }

        try {
            $order = $record->order ?? null;

            if (! $order && ! empty($record->order_id)) {
                $order = Order::query()
                    ->select(['id', 'user_id'])
                    ->whereKey($record->order_id)
                    ->first();
            }

            $orderUserId = (int) ($order?->user_id ?? optional($order?->user)->id ?? 0);
            if ($orderUserId > 0) {
                return $orderUserId;
            }
        } catch (\Throwable $e) {
            //
        }

        $email = trim((string) ($record->email ?? ''));
        if ($email !== '') {
            try {
                $orderUserId = (int) (Order::query()
                    ->where('email', $email)
                    ->whereNotNull('user_id')
                    ->latest('id')
                    ->value('user_id') ?? 0);

                if ($orderUserId > 0) {
                    return $orderUserId;
                }
            } catch (\Throwable $e) {
                //
            }

            try {
                $pendingUserId = (int) (PendingOrder::query()
                    ->where('email', $email)
                    ->whereNotNull('user_id')
                    ->latest('id')
                    ->value('user_id') ?? 0);

                if ($pendingUserId > 0) {
                    return $pendingUserId;
                }
            } catch (\Throwable $e) {
                //
            }
        }

        return 0;
    }

    protected static function sixMonthReviewHistoryForAppointment($record): string
    {
        if (! $record) {
            return '—';
        }

        $userId = static::sixMonthReviewUserIdForAppointment($record);
        if ($userId < 1) {
            return '—';
        }

        $rows = collect();

        $collectFromRecord = function ($sourceRecord, string $sourceLabel) use (&$rows) {
            if (! $sourceRecord) {
                return;
            }

            $meta = is_array($sourceRecord->meta)
                ? $sourceRecord->meta
                : (json_decode($sourceRecord->meta ?? '[]', true) ?: []);

            $reviews = data_get($meta, 'six_month_reviews', []);
            if (! is_array($reviews)) {
                return;
            }

            foreach ($reviews as $review) {
                if (! is_array($review)) {
                    continue;
                }

                $text = trim((string) ($review['text'] ?? $review['review'] ?? $review['note'] ?? ''));
                $date = $review['date'] ?? $review['created_at'] ?? $sourceRecord->created_at ?? null;

                try {
                    $dateText = $date ? Carbon::parse($date)->format('d-m-Y H:i') : '—';
                } catch (\Throwable $e) {
                    $dateText = (string) $date;
                }

                $reference = (string) ($sourceRecord->reference ?? '');

                $formatNumber = function ($value, int $decimals = 1): string {
                    if ($value === null || $value === '') {
                        return '';
                    }

                    $formatted = number_format((float) $value, $decimals, '.', '');

                    return str_contains($formatted, '.')
                        ? rtrim(rtrim($formatted, '0'), '.')
                        : $formatted;
                };

                $heightUnit = strtolower(trim((string) ($review['height_unit'] ?? '')));
                $weightUnit = strtolower(trim((string) ($review['weight_unit'] ?? '')));

                $heightText = '';
                if ($heightUnit === 'imperial') {
                    $heightParts = [];
                    if (($review['height_ft'] ?? null) !== null && $review['height_ft'] !== '') {
                        $heightParts[] = $formatNumber($review['height_ft']) . ' ft';
                    }
                    if (($review['height_in'] ?? null) !== null && $review['height_in'] !== '') {
                        $heightParts[] = $formatNumber($review['height_in']) . ' in';
                    }
                    $heightText = trim(implode(' ', $heightParts));
                } elseif (($review['height_cm'] ?? null) !== null && $review['height_cm'] !== '') {
                    $heightText = $formatNumber($review['height_cm']) . ' cm';
                } elseif (($review['height'] ?? null) !== null && trim((string) $review['height']) !== '') {
                    $heightText = trim((string) $review['height']);
                }

                $weightText = '';
                if ($weightUnit === 'imperial') {
                    $weightParts = [];
                    if (($review['weight_st'] ?? null) !== null && $review['weight_st'] !== '') {
                        $weightParts[] = $formatNumber($review['weight_st']) . ' st';
                    }
                    if (($review['weight_lb'] ?? null) !== null && $review['weight_lb'] !== '') {
                        $weightParts[] = $formatNumber($review['weight_lb']) . ' lb';
                    }
                    $weightText = trim(implode(' ', $weightParts));
                } elseif (($review['weight_kg'] ?? null) !== null && $review['weight_kg'] !== '') {
                    $weightText = $formatNumber($review['weight_kg']) . ' kg';
                } elseif (($review['weight'] ?? null) !== null && trim((string) $review['weight']) !== '') {
                    $weightText = trim((string) $review['weight']);
                }

                $bmiText = '';
                if (($review['bmi'] ?? null) !== null && $review['bmi'] !== '') {
                    $bmiText = $formatNumber($review['bmi']);
                }

                $measurementParts = [];
                if ($heightText !== '') {
                    $measurementParts[] = 'Height: ' . $heightText;
                }
                if ($weightText !== '') {
                    $measurementParts[] = 'Weight: ' . $weightText;
                }
                if ($bmiText !== '') {
                    $measurementParts[] = 'BMI: ' . $bmiText;
                }

                if ($text === '' && empty($measurementParts)) {
                    continue;
                }

                $rows->push([
                    'sort' => $date ? strtotime((string) $date) : 0,
                    'date' => $dateText,
                    'text' => $text,
                    'measurements' => implode(' | ', $measurementParts),
                    'reference' => $reference,
                    'source' => $sourceLabel,
                ]);
            }
        };

        try {
            PendingOrder::query()
                ->where('user_id', $userId)
                ->whereNotNull('meta')
                ->latest('id')
                ->limit(50)
                ->get()
                ->each(fn ($pending) => $collectFromRecord($pending, 'Pending'));
        } catch (\Throwable $e) {
            //
        }

        try {
            Order::query()
                ->where('user_id', $userId)
                ->whereNotNull('meta')
                ->latest('id')
                ->limit(50)
                ->get()
                ->each(fn ($order) => $collectFromRecord($order, 'Order'));
        } catch (\Throwable $e) {
            //
        }

        $rows = $rows
            ->sortByDesc('sort')
            ->unique(fn ($row) => implode('|', [$row['date'], $row['text'], $row['measurements'], $row['reference']]))
            ->values();

        if ($rows->isEmpty()) {
            return '—';
        }

        return $rows->map(function ($row) {
            $parts = ['[' . $row['date'] . ']'];

            if (! empty($row['measurements'])) {
                $parts[] = $row['measurements'];
            }

            if (! empty($row['text'])) {
                $parts[] = $row['text'];
            }

            return implode("\n", $parts);
        })->implode("\n\n");
    }
    protected static ?string $model = Appointment::class;
    protected static array $relatedOrderCache = [];
    protected static array $relatedPendingCache = [];

    // Sidebar placement
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Appointments';
    protected static string | \UnitEnum | null $navigationGroup = 'Notifications';
    protected static ?int    $navigationSort  = 5;

    // Title used on View/Edit pages
    protected static ?string $recordTitleAttribute = 'display_title';

    /**
     * Helper: Return a list of service options for appointment form selects.
     */
    protected static function appointmentServiceOptions(?string $search = null): array
    {
        if (! SchemaFacade::hasTable('services')) {
            return [];
        }

        $query = DB::table('services');

        if (filled($search)) {
            $query->where('name', 'like', '%' . trim((string) $search) . '%');
        }

        return $query
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->mapWithKeys(function ($service) {
                $name = trim((string) ($service->name ?? ''));
                return $name !== '' ? [(string) $service->id => $name] : [];
            })
            ->all();
    }

    /**
     * Helper: Return the label of a service by ID.
     */
    protected static function appointmentServiceLabel(mixed $serviceId): ?string
    {
        if (! $serviceId || ! SchemaFacade::hasTable('services')) {
            return null;
        }

        try {
            $name = DB::table('services')->where('id', $serviceId)->value('name');
            $name = is_string($name) ? trim($name) : '';
            return $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Helper: Return item options for a given service for appointment form selects.
     */
    protected static function appointmentItemOptionsForService(mixed $serviceId, ?string $search = null): array
    {
        if (empty($serviceId)) {
            return [];
        }

        $search = trim((string) ($search ?? ''));
        $options = [];

        if (SchemaFacade::hasTable('products')) {
            $nameColumn = SchemaFacade::hasColumn('products', 'name')
                ? 'name'
                : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

            if ($nameColumn) {
                try {
                    if (SchemaFacade::hasTable('service_product')) {
                        $query = DB::table('products')
                            ->join('service_product', 'products.id', '=', 'service_product.product_id')
                            ->where('service_product.service_id', $serviceId);

                        if ($search !== '') {
                            $query->where("products.{$nameColumn}", 'like', '%' . $search . '%');
                        }

                        $options = $query
                            ->orderBy("products.{$nameColumn}")
                            ->limit(100)
                            ->get(["products.{$nameColumn} as product_name"])
                            ->mapWithKeys(fn ($row) => [(string) $row->product_name => (string) $row->product_name])
                            ->all();
                    } elseif (SchemaFacade::hasTable('product_service')) {
                        $query = DB::table('products')
                            ->join('product_service', 'products.id', '=', 'product_service.product_id')
                            ->where('product_service.service_id', $serviceId);

                        if ($search !== '') {
                            $query->where("products.{$nameColumn}", 'like', '%' . $search . '%');
                        }

                        $options = $query
                            ->orderBy("products.{$nameColumn}")
                            ->limit(100)
                            ->get(["products.{$nameColumn} as product_name"])
                            ->mapWithKeys(fn ($row) => [(string) $row->product_name => (string) $row->product_name])
                            ->all();
                    }
                } catch (\Throwable $e) {
                    $options = [];
                }
            }
        }

        if (empty($options) && SchemaFacade::hasTable('service_medicines')) {
            $nameColumn = SchemaFacade::hasColumn('service_medicines', 'name')
                ? 'name'
                : (SchemaFacade::hasColumn('service_medicines', 'title') ? 'title' : null);

            if ($nameColumn) {
                try {
                    $query = DB::table('service_medicines')
                        ->where('service_id', $serviceId);

                    if ($search !== '') {
                        $query->where($nameColumn, 'like', '%' . $search . '%');
                    }

                    $options = $query
                        ->orderBy($nameColumn)
                        ->limit(100)
                        ->get(["{$nameColumn} as medicine_name"])
                        ->mapWithKeys(fn ($row) => [(string) $row->medicine_name => (string) $row->medicine_name])
                        ->all();
                } catch (\Throwable $e) {
                    $options = [];
                }
            }
        }

        return array_filter($options, fn ($label) => trim((string) $label) !== '');
    }

    // ---- Appointment slot/schedule helpers ----

    /**
     * Helper: Decode a JSON array or return array as-is.
     */
    protected static function appointmentDecodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Helper: Get the service slug for slot lookup, optionally using fallback slug/name.
     */
    protected static function appointmentServiceSlugForSlotLookup(mixed $serviceId = null, ?string $fallbackSlug = null, ?string $fallbackName = null): ?string
    {
        $fallbackSlug = is_string($fallbackSlug) ? trim($fallbackSlug) : '';
        if ($fallbackSlug !== '') {
            return Str::slug($fallbackSlug);
        }

        if ($serviceId && SchemaFacade::hasTable('services')) {
            try {
                $service = DB::table('services')->where('id', $serviceId)->first();
                if ($service) {
                    $slug = trim((string) ($service->slug ?? ''));
                    if ($slug !== '') {
                        return Str::slug($slug);
                    }

                    $name = trim((string) ($service->name ?? ''));
                    if ($name !== '') {
                        return Str::slug($name);
                    }
                }
            } catch (\Throwable $e) {
                // fall back below
            }
        }

        $fallbackName = is_string($fallbackName) ? trim($fallbackName) : '';
        return $fallbackName !== '' ? Str::slug($fallbackName) : null;
    }

    /**
     * Helper: Get the schedule row for a given service slug.
     */
    protected static function appointmentScheduleForService(?string $serviceSlug): ?object
    {
        if (! SchemaFacade::hasTable('schedules')) {
            return null;
        }

        $serviceSlug = $serviceSlug ? Str::slug($serviceSlug) : null;
        $serviceSlugVariants = [];

        if ($serviceSlug) {
            $serviceSlugVariants = array_values(array_unique(array_filter([
                $serviceSlug,
                str_replace('-', '_', $serviceSlug),
                str_replace('-', ' ', $serviceSlug),
                Str::lower($serviceSlug),
            ])));
        }

        try {
            $query = DB::table('schedules');

            if (SchemaFacade::hasColumn('schedules', 'is_active')) {
                $query->where(function ($q) {
                    $q->where('is_active', 1)->orWhereNull('is_active');
                });
            }

            if ($serviceSlug && SchemaFacade::hasColumn('schedules', 'service_slug')) {
                $matched = (clone $query)
                    ->where(function ($q) use ($serviceSlugVariants) {
                        foreach ($serviceSlugVariants as $variant) {
                            $q->orWhere('service_slug', $variant);
                        }
                    })
                    ->orderByDesc('id')
                    ->first();

                if ($matched) {
                    return $matched;
                }
            }

            if ($serviceSlug && SchemaFacade::hasColumn('schedules', 'service')) {
                $matched = (clone $query)
                    ->where(function ($q) use ($serviceSlugVariants) {
                        foreach ($serviceSlugVariants as $variant) {
                            $q->orWhere('service', $variant);
                        }
                    })
                    ->orderByDesc('id')
                    ->first();

                if ($matched) {
                    return $matched;
                }
            }

            if ($serviceSlug && SchemaFacade::hasColumn('schedules', 'name')) {
                $matched = (clone $query)
                    ->where(function ($q) use ($serviceSlug, $serviceSlugVariants) {
                        $q->where('name', 'like', '%' . str_replace('-', '%', $serviceSlug) . '%');

                        foreach ($serviceSlugVariants as $variant) {
                            $q->orWhere('name', 'like', '%' . str_replace(['-', '_'], '%', $variant) . '%');
                        }
                    })
                    ->orderByDesc('id')
                    ->first();

                if ($matched) {
                    return $matched;
                }
            }

            return $query
                ->when(SchemaFacade::hasColumn('schedules', 'service_slug'), function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('service_slug')
                            ->orWhere('service_slug', '')
                            ->orWhere('service_slug', 'global');
                    });
                })
                ->orderByRaw("CASE WHEN service_slug = 'global' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function appointmentScheduleTimezone(?object $schedule): string
    {
        $tz = is_string($schedule->timezone ?? null) ? trim($schedule->timezone) : '';
        return $tz !== '' ? $tz : 'Europe/London';
    }

    protected static function appointmentScheduleSlotMinutes(?object $schedule): int
    {
        $minutes = (int) ($schedule->slot_minutes ?? 20);
        return $minutes > 0 ? $minutes : 20;
    }

    protected static function appointmentScheduleCapacity(?object $schedule): int
    {
        $capacity = (int) ($schedule->capacity ?? 1);
        return $capacity > 0 ? $capacity : 1;
    }

    protected static function appointmentDayKey(string $date, string $timezone): string
    {
        try {
            return Str::lower(Carbon::parse($date, $timezone)->format('D'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Helper: Extract a schedule JSON array from the first non-empty of the given columns.
     */
    protected static function appointmentScheduleJsonFromColumns(?object $schedule, array $columns): array
    {
        if (! $schedule) {
            return [];
        }

        foreach ($columns as $column) {
            if (isset($schedule->{$column})) {
                $decoded = static::appointmentDecodeArray($schedule->{$column});
                if (! empty($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * Helper: Normalize schedule day value to a 3-letter key (sun, mon, ...).
     */
    protected static function appointmentNormaliseScheduleDay(mixed $day): string
    {
        if (is_int($day) || ctype_digit((string) $day)) {
            $n = (int) $day;
            $map = [
                0 => 'sun',
                1 => 'mon',
                2 => 'tue',
                3 => 'wed',
                4 => 'thu',
                5 => 'fri',
                6 => 'sat',
                7 => 'sun',
            ];

            return $map[$n] ?? '';
        }

        $day = Str::lower(trim((string) $day));
        if ($day === '') {
            return '';
        }

        $aliases = [
            'monday' => 'mon',
            'tuesday' => 'tue',
            'wednesday' => 'wed',
            'thursday' => 'thu',
            'friday' => 'fri',
            'saturday' => 'sat',
            'sunday' => 'sun',
        ];

        return $aliases[$day] ?? substr($day, 0, 3);
    }

    /**
     * Helper: Flatten schedule rows from normal JSON, Filament UUID-keyed repeaters,
     * and Livewire synthesised nested arrays.
     */
    protected static function appointmentFlattenScheduleRows(array $rows): array
    {
        $flat = [];

        $scheduleKeys = [
            'day',
            'dow',
            'weekday',
            'day_of_week',
            'dayOfWeek',
            'date',
            'on',
            'override_date',
            'overrideDate',
            'start',
            'from',
            'start_time',
            'startTime',
            'end',
            'to',
            'end_time',
            'endTime',
            'open',
            'enabled',
            'closed',
        ];

        $walk = function (mixed $value) use (&$walk, &$flat, $scheduleKeys): void {
            if (! is_array($value)) {
                return;
            }

            $hasScheduleShape = array_intersect(array_keys($value), $scheduleKeys);
            if (! empty($hasScheduleShape)) {
                $flat[] = $value;
                return;
            }

            foreach ($value as $nested) {
                if (is_array($nested)) {
                    $walk($nested);
                }
            }
        };

        $walk($rows);

        return $flat;
    }

    /**
     * Helper: Truthy check for "open" fields in schedules.
     */
    protected static function appointmentTruthOpen(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }

        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        $value = Str::lower(trim((string) $value));

        return $value !== '' && ! in_array($value, ['0', 'false', 'closed', 'no', 'off'], true);
    }

    /**
     * Helper: Return the first non-empty string value from $row using $keys.
     */
    protected static function appointmentFirstScheduleValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = data_get($row, $key);
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Helper: Get [windows, blackouts] for a given schedule and date.
     */
    protected static function appointmentScheduleWindowsForDate(?object $schedule, string $date): array
    {
        if (! $schedule || trim($date) === '') {
            return [];
        }

        $timezone = static::appointmentScheduleTimezone($schedule);
        $dayKey = static::appointmentDayKey($date, $timezone);
        if ($dayKey === '') {
            return [[], []];
        }

        $windows = [];
        $blackouts = [];

        $week = static::appointmentScheduleJsonFromColumns($schedule, [
            'week',
            'weekly_hours',
            'weeklyHours',
            'working_hours',
            'workingHours',
            'opening_hours',
            'openingHours',
            'hours',
            'days',
            'availability',
            'rules',
        ]);

        foreach (static::appointmentFlattenScheduleRows($week) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowDayRaw = $row['day']
                ?? $row['dow']
                ?? $row['weekday']
                ?? $row['day_of_week']
                ?? $row['dayOfWeek']
                ?? $key;

            $rowDay = static::appointmentNormaliseScheduleDay($rowDayRaw);
            if ($rowDay !== $dayKey) {
                continue;
            }

            $openFlag = $row['open'] ?? $row['enabled'] ?? $row['is_open'] ?? $row['isOpen'] ?? $row['closed'] ?? true;
            if (array_key_exists('closed', $row)) {
                $openFlag = ! $row['closed'];
            }

            if (! static::appointmentTruthOpen($openFlag)) {
                return [[], []];
            }

            $start = static::appointmentFirstScheduleValue($row, ['start', 'from', 'start_time', 'startTime', 'open_time', 'openTime', 'opens', 'opening']);
            $end = static::appointmentFirstScheduleValue($row, ['end', 'to', 'end_time', 'endTime', 'close_time', 'closeTime', 'closes', 'closing']);

            if ($start !== '' && $end !== '') {
                $windows[] = [$start, $end];
            }

            $breakStart = static::appointmentFirstScheduleValue($row, ['break_start', 'breakStart', 'lunch_start', 'lunchStart']);
            $breakEnd = static::appointmentFirstScheduleValue($row, ['break_end', 'breakEnd', 'lunch_end', 'lunchEnd']);
            if ($breakStart !== '' && $breakEnd !== '') {
                $blackouts[] = [$breakStart, $breakEnd];
            }

            foreach ((array) ($row['breaks'] ?? []) as $break) {
                if (! is_array($break)) {
                    continue;
                }

                $bs = static::appointmentFirstScheduleValue($break, ['start', 'from', 'start_time', 'startTime']);
                $be = static::appointmentFirstScheduleValue($break, ['end', 'to', 'end_time', 'endTime']);
                if ($bs !== '' && $be !== '') {
                    $blackouts[] = [$bs, $be];
                }
            }
        }

        $overrides = static::appointmentScheduleJsonFromColumns($schedule, [
            'overrides',
            'date_overrides',
            'dateOverrides',
            'special_days',
            'specialDays',
            'exceptions',
        ]);

        foreach (static::appointmentFlattenScheduleRows($overrides) as $override) {
            if (! is_array($override)) {
                continue;
            }

            $overrideDate = static::appointmentFirstScheduleValue($override, ['date', 'on', 'day', 'override_date', 'overrideDate']);
            if ($overrideDate !== $date) {
                continue;
            }

            $openFlag = $override['open'] ?? $override['is_open'] ?? $override['isOpen'] ?? $override['closed'] ?? true;
            if (array_key_exists('closed', $override)) {
                $openFlag = ! $override['closed'];
            }

            if (! static::appointmentTruthOpen($openFlag)) {
                return [[], []];
            }

            $start = static::appointmentFirstScheduleValue($override, ['start', 'from', 'start_time', 'startTime', 'open_time', 'openTime']);
            $end = static::appointmentFirstScheduleValue($override, ['end', 'to', 'end_time', 'endTime', 'close_time', 'closeTime']);
            if ($start !== '' && $end !== '') {
                $windows = [[$start, $end]];
            }

            foreach ((array) ($override['blackouts'] ?? []) as $blackout) {
                if (! is_array($blackout)) {
                    continue;
                }
                $bs = static::appointmentFirstScheduleValue($blackout, ['start', 'from', 'start_time', 'startTime']);
                $be = static::appointmentFirstScheduleValue($blackout, ['end', 'to', 'end_time', 'endTime']);
                if ($bs !== '' && $be !== '') {
                    $blackouts[] = [$bs, $be];
                }
            }
        }

        $scheduleBlackouts = static::appointmentScheduleJsonFromColumns($schedule, [
            'blackouts',
            'blocked_times',
            'blockedTimes',
            'breaks',
        ]);

        foreach (static::appointmentFlattenScheduleRows($scheduleBlackouts) as $blackout) {
            if (! is_array($blackout)) {
                continue;
            }

            $blackoutDate = static::appointmentFirstScheduleValue($blackout, ['date', 'on', 'day']);
            if ($blackoutDate !== '' && $blackoutDate !== $date) {
                continue;
            }

            $bs = static::appointmentFirstScheduleValue($blackout, ['start', 'from', 'start_time', 'startTime']);
            $be = static::appointmentFirstScheduleValue($blackout, ['end', 'to', 'end_time', 'endTime']);
            if ($bs !== '' && $be !== '') {
                $blackouts[] = [$bs, $be];
            }
        }

        return [$windows, $blackouts];
    }

    /**
     * Helper: Check if a slot overlaps any blackout.
     */
    protected static function appointmentTimeOverlapsBlackout(Carbon $slotStart, Carbon $slotEnd, string $date, string $timezone, array $blackouts): bool
    {
        foreach ($blackouts as $blackout) {
            [$start, $end] = $blackout;
            try {
                $blackoutStart = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $start, $timezone);
                $blackoutEnd = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $end, $timezone);
                if ($slotStart->lt($blackoutEnd) && $slotEnd->gt($blackoutStart)) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Helper: Count appointments already booked for a slot, excluding a given appointment ID.
     * Kept as a compatibility wrapper. The main slot builder loads booked counts once per date.
     */
    protected static function appointmentBookedCountForSlot(Carbon $slotStartLocal, ?int $ignoreAppointmentId = null): int
    {
        $timezone = 'Europe/London';
        $counts = static::appointmentBookedCountsForDate(
            $slotStartLocal->copy()->tz($timezone)->toDateString(),
            $timezone,
            $ignoreAppointmentId
        );

        $key = $slotStartLocal->copy()->utc()->format('Y-m-d H:i:s');

        return (int) ($counts[$key] ?? 0);
    }

    /**
     * Helper: Fetch booked appointment counts for a whole local date in one query.
     * Returns multiple keys per booking so the create form can hide taken times even if
     * older rows stored start_at as local time rather than UTC, or use appointment_at/start_date/start_time.
     */
    protected static function appointmentBookedCountsForDate(string $date, string $timezone, ?int $ignoreAppointmentId = null): array
    {
        try {
            $dayStartLocal = Carbon::parse($date, $timezone)->startOfDay();
            $dayEndLocal = Carbon::parse($date, $timezone)->endOfDay();
            $dayStartUtc = $dayStartLocal->copy()->utc()->format('Y-m-d H:i:s');
            $dayEndUtc = $dayEndLocal->copy()->utc()->format('Y-m-d H:i:s');

            $query = Appointment::query()
                ->when($ignoreAppointmentId, fn ($q) => $q->whereKeyNot($ignoreAppointmentId))
                ->when(SchemaFacade::hasColumn('appointments', 'status'), function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('status')
                            ->orWhere('status', '')
                            ->orWhereNotIn('status', ['completed', 'complete', 'done', 'cancelled', 'canceled', 'rejected']);
                    });
                });

            $query->where(function ($q) use ($date, $dayStartUtc, $dayEndUtc) {
                if (SchemaFacade::hasColumn('appointments', 'start_at')) {
                    $q->orWhereBetween('start_at', [$dayStartUtc, $dayEndUtc])
                        ->orWhereDate('start_at', $date);
                }

                if (SchemaFacade::hasColumn('appointments', 'appointment_at')) {
                    $q->orWhereBetween('appointment_at', [$dayStartUtc, $dayEndUtc])
                        ->orWhereDate('appointment_at', $date);
                }

                if (SchemaFacade::hasColumn('appointments', 'start_date')) {
                    $q->orWhereDate('start_date', $date);
                }
            });

            $rows = $query->get();
            $counts = [];

            $addBookedTime = function (Carbon $local) use (&$counts, $date): void {
                if ($local->toDateString() !== $date) {
                    return;
                }

                $time = $local->format('H:i');
                $utcKey = $local->copy()->utc()->format('Y-m-d H:i:s');
                $localKey = $local->copy()->format('Y-m-d H:i:s');

                $counts[$utcKey] = (int) ($counts[$utcKey] ?? 0) + 1;
                $counts[$localKey] = (int) ($counts[$localKey] ?? 0) + 1;
                $counts[$time] = (int) ($counts[$time] ?? 0) + 1;
            };

            foreach ($rows as $row) {
                $candidateStart = null;

                foreach (['start_at', 'appointment_at'] as $column) {
                    if (SchemaFacade::hasColumn('appointments', $column) && ! empty($row->{$column})) {
                        $candidateStart = $row->{$column};
                        break;
                    }
                }

                if ($candidateStart) {
                    try {
                        $addBookedTime(Carbon::parse($candidateStart, 'UTC')->tz($timezone));
                        continue;
                    } catch (\Throwable $e) {
                        // Try interpreting the stored value as local below.
                    }

                    try {
                        $addBookedTime(Carbon::parse($candidateStart, $timezone));
                        continue;
                    } catch (\Throwable $e) {
                        // Fall through to explicit date/time columns.
                    }
                }

                if (
                    SchemaFacade::hasColumn('appointments', 'start_date')
                    && SchemaFacade::hasColumn('appointments', 'start_time')
                ) {
                    $rowDate = ! empty($row->start_date)
                        ? Carbon::parse($row->start_date)->toDateString()
                        : null;

                    $rowTime = is_string($row->start_time ?? null)
                        ? substr(trim($row->start_time), 0, 5)
                        : null;

                    if ($rowDate === $date && $rowTime) {
                        $addBookedTime(Carbon::parse($date . ' ' . $rowTime, $timezone));
                    }
                }
            }

            return $counts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Helper: Generate available time slot options for a service and date.
     */
    protected static function appointmentAvailableTimeOptions(?string $serviceSlug, ?string $date, ?int $ignoreAppointmentId = null): array
    {
        $date = is_string($date) ? trim($date) : '';
        if ($date === '') {
            return [];
        }

        $schedule = static::appointmentScheduleForService($serviceSlug);
        if (! $schedule) {
            return [];
        }

        $timezone = static::appointmentScheduleTimezone($schedule);
        $slotMinutes = static::appointmentScheduleSlotMinutes($schedule);
        $capacity = static::appointmentScheduleCapacity($schedule);
        [$windows, $blackouts] = static::appointmentScheduleWindowsForDate($schedule, $date);

        if (empty($windows)) {
            return [];
        }

        $bookedCounts = static::appointmentBookedCountsForDate($date, $timezone, $ignoreAppointmentId);

        $options = [];
        foreach ($windows as $window) {
            [$start, $end] = $window;
            try {
                $cursor = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $start, $timezone);
                $windowEnd = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $end, $timezone);
            } catch (\Throwable $e) {
                continue;
            }

            while ($cursor->copy()->addMinutes($slotMinutes)->lte($windowEnd)) {
                $slotStart = $cursor->copy();
                $slotEnd = $cursor->copy()->addMinutes($slotMinutes);

                if (! static::appointmentTimeOverlapsBlackout($slotStart, $slotEnd, $date, $timezone, $blackouts)) {
                    $time = $slotStart->format('H:i');
                    $slotUtcKey = $slotStart->copy()->utc()->format('Y-m-d H:i:s');
                    $slotLocalKey = $slotStart->copy()->format('Y-m-d H:i:s');
                    $booked = max(
                        (int) ($bookedCounts[$slotUtcKey] ?? 0),
                        (int) ($bookedCounts[$slotLocalKey] ?? 0),
                        (int) ($bookedCounts[$time] ?? 0)
                    );

                    if ($booked < $capacity) {
                        $options[$time] = $time;
                    }
                }

                $cursor->addMinutes($slotMinutes);
            }
        }

        return $options;
    }

    /**
 * Final server-side guard used by create/edit/reschedule.
 * Prevents duplicate saves even if the dropdown options were loaded before another booking was created.
 */
public static function appointmentSlotHasCapacityForStartAt(?string $startAtUtc, ?string $serviceSlug = null, ?int $ignoreAppointmentId = null): bool
{
    $startAtUtc = is_string($startAtUtc) ? trim($startAtUtc) : '';

    if ($startAtUtc === '') {
        return false;
    }

    $schedule = static::appointmentScheduleForService($serviceSlug);

    if (! $schedule) {
        return false;
    }

    $timezone = static::appointmentScheduleTimezone($schedule);

    try {
        $local = Carbon::parse($startAtUtc, 'UTC')->tz($timezone);
    } catch (\Throwable $e) {
        return false;
    }

    $date = $local->toDateString();
    $time = $local->format('H:i');

    $availableOptions = static::appointmentAvailableTimeOptions($serviceSlug, $date, $ignoreAppointmentId);

    if (! array_key_exists($time, $availableOptions)) {
        return false;
    }

    $counts = static::appointmentBookedCountsForDate($date, $timezone, $ignoreAppointmentId);
    $utcKey = $local->copy()->utc()->format('Y-m-d H:i:s');
    $localKey = $local->copy()->format('Y-m-d H:i:s');
    $capacity = static::appointmentScheduleCapacity($schedule);
    $booked = max(
        (int) ($counts[$utcKey] ?? 0),
        (int) ($counts[$localKey] ?? 0),
        (int) ($counts[$time] ?? 0)
    );

    return $booked < $capacity;
}

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
                \Filament\Forms\Components\Select::make('service_id')
                    ->label('Service')
                    ->searchable()
                    ->preload()
                    ->options(fn () => static::appointmentServiceOptions())
                    ->getSearchResultsUsing(fn (string $search): array => static::appointmentServiceOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => static::appointmentServiceLabel($value))
                    ->live()
                    ->dehydrated(fn () => SchemaFacade::hasColumn('appointments', 'service_id'))
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Set $set) {
                        $serviceName = null;
                        $serviceSlug = null;

                        if ($state && SchemaFacade::hasTable('services')) {
                            try {
                                $service = DB::table('services')->where('id', $state)->first();
                                if ($service) {
                                    $serviceName = trim((string) ($service->name ?? '')) ?: null;
                                    $serviceSlug = trim((string) ($service->slug ?? '')) ?: ($serviceName ? Str::slug($serviceName) : null);
                                }
                            } catch (\Throwable $e) {
                                $serviceName = null;
                                $serviceSlug = null;
                            }
                        }

                        $set('service', $serviceName);
                        $set('service_slug', $serviceSlug);
                        $set('service_name', null);
                        $set('start_date', null);
                        $set('start_time', null);
                        $set('start_at', null);
                        $set('end_at', null);
                    })
                    ->required()
                    ->columnSpan(4),

                \Filament\Forms\Components\Select::make('service_name')
                    ->label('Item')
                    ->searchable()
                    ->preload()
                    ->options(fn (\Filament\Schemas\Components\Utilities\Get $get): array => static::appointmentItemOptionsForService($get('service_id')))
                    ->getSearchResultsUsing(fn (string $search, \Filament\Schemas\Components\Utilities\Get $get): array => static::appointmentItemOptionsForService($get('service_id'), $search))
                    ->placeholder('Select a service first')
                    ->columnSpan(5),

                \Filament\Forms\Components\Hidden::make('start_at')
                    ->required(),
                \Filament\Forms\Components\Hidden::make('end_at')
                    ->dehydrated(fn () => \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'end_at')),

                \Filament\Forms\Components\DatePicker::make('start_date')
                    ->label('Date')
                    ->native(false)
                    ->minDate(now('Europe/London')->toDateString())
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function ($state, \Filament\Schemas\Components\Utilities\Set $set, $record) {
                        if (! $record || empty($record->start_at)) {
                            return;
                        }
                        try {
                            $dt = \Carbon\Carbon::parse($record->start_at, 'UTC')->tz('Europe/London');
                            $set('start_date', $dt->format('Y-m-d'));
                        } catch (\Throwable $e) {
                        }
                    })
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                        $set('start_time', null);
                        $set('start_at', null);
                        $set('end_at', null);
                    })
                    ->columnSpan(6),

                \Filament\Forms\Components\Select::make('start_time')
                    ->label('Time')
                    ->native(false)
                    ->options(function (\Filament\Schemas\Components\Utilities\Get $get, $record = null): array {
                        $options = [];

                        for ($hour = 9; $hour <= 18; $hour++) {
                            $hh = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

                            if ($hour === 18) {
                                $options[$hh . ':00'] = $hh . ':00';
                                continue;
                            }

                            for ($minute = 0; $minute < 60; $minute += 15) {
                                $mm = str_pad((string) $minute, 2, '0', STR_PAD_LEFT);
                                $options[$hh . ':' . $mm] = $hh . ':' . $mm;
                            }
                        }

                        $date = $get('start_date');
                        if (! $date) {
                            return $options;
                        }

                        try {
                            $dayStartUtc = Carbon::parse($date, 'Europe/London')
                                ->startOfDay()
                                ->utc()
                                ->format('Y-m-d H:i:s');

                            $dayEndUtc = Carbon::parse($date, 'Europe/London')
                                ->endOfDay()
                                ->utc()
                                ->format('Y-m-d H:i:s');

                            $bookedRows = Appointment::query()
                                ->when($record?->getKey(), fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->when(SchemaFacade::hasColumn('appointments', 'status'), function ($query) {
                                    $query->where(function ($statusQuery) {
                                        $statusQuery
                                            ->whereNull('status')
                                            ->orWhere('status', '')
                                            ->orWhereNotIn('status', [
                                                'completed',
                                                'complete',
                                                'done',
                                                'cancelled',
                                                'canceled',
                                                'rejected',
                                            ]);
                                    });
                                })
                                ->whereBetween('start_at', [$dayStartUtc, $dayEndUtc])
                                ->get(['id', 'start_at']);

                            foreach ($bookedRows as $bookedRow) {
                                $rawStartAt = method_exists($bookedRow, 'getRawOriginal')
                                    ? $bookedRow->getRawOriginal('start_at')
                                    : $bookedRow->start_at;

                                if (! $rawStartAt) {
                                    continue;
                                }

                                $bookedTime = Carbon::parse($rawStartAt, 'UTC')
                                    ->tz('Europe/London')
                                    ->format('H:i');

                                unset($options[$bookedTime]);
                            }
                        } catch (\Throwable $e) {
                            return $options;
                        }

                        return $options;
                    })
                    ->placeholder('Select a time between 09:00 and 18:00')
                    ->searchable()
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function ($state, \Filament\Schemas\Components\Utilities\Set $set, $record) {
                        if (! $record || empty($record->start_at)) {
                            return;
                        }
                        try {
                            $dt = \Carbon\Carbon::parse($record->start_at, 'UTC')->tz('Europe/London');
                            $t = $dt->format('H:i');
                            $set('start_time', $t);
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
                            $dt = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $time, 'Europe/London');
                            $set('start_at', $dt->copy()->utc()->format('Y-m-d H:i:s'));
                            $set('end_at', $dt->copy()->utc()->addMinutes(20)->format('Y-m-d H:i:s'));
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

                \Filament\Forms\Components\Hidden::make('service')
                    ->dehydrated(true),

                \Filament\Forms\Components\Hidden::make('service_slug')
                    ->dehydrated(fn () => SchemaFacade::hasColumn('appointments', 'service_slug')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                return static::applyPendingOnlyAppointmentsConstraints($query)
                    ->select('appointments.*')
                    ->with(['order.user'])
                    ->orderBy('appointments.start_at', 'asc');
            })
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('start_at')
                    ->label('When')
                    ->getStateUsing(function ($record) {
                        if (! $record) {
                            return null;
                        }

                        try {
                            $rawStartAt = method_exists($record, 'getRawOriginal')
                                ? $record->getRawOriginal('start_at')
                                : $record->start_at;

                            if (empty($rawStartAt)) {
                                return null;
                            }

                            return Carbon::parse($rawStartAt, 'UTC')
                                ->tz('Europe/London')
                                ->format('d M Y, H:i');
                        } catch (\Throwable $e) {
                            return null;
                        }
                    })
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reference')
                    ->label('Ref')
                    ->getStateUsing(function ($record) {
                        if (! $record) {
                            return '—';
                        }

                        $stored = is_string($record->order_reference ?? null) ? trim((string) $record->order_reference) : '';
                        if ($stored !== '') {
                            return $stored;
                        }

                        $id = $record->getKey();
                        if ($id) {
                            return 'PCAO' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
                        }

                        return '—';
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

                        // Prefer the linked order/cart item first. Appointment service_name/service is usually
                        // just the service label, e.g. "Weight Management", not the purchased item.
                        $ord = $record->order ?? null;

                        if (! $ord && ! empty($record->order_id)) {
                            $ord = Order::query()
                                ->with('user')
                                ->whereKey($record->order_id)
                                ->first();
                        }

                        $appointmentItem = is_string($record->service_name ?? null) ? trim($record->service_name) : '';
                        $appointmentService = is_string($record->service ?? null) ? trim($record->service) : '';

                        if (! $ord) {
                            if ($appointmentItem !== '') {
                                return $appointmentItem;
                            }

                            if ($appointmentService !== '') {
                                return $appointmentService;
                            }

                            return '—';
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
                            if ($appointmentItem !== '') {
                                return $appointmentItem;
                            }

                            if ($appointmentService !== '') {
                                return $appointmentService;
                            }

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

                        // 3) Avoid expensive fallback order lookups on the appointment list unless the row is directly linked.
                        $order = $record->order ?? null;

                        if (! $order && ! empty($record->order_id)) {
                            $order = Order::query()
                                ->with('user')
                                ->whereKey($record->order_id)
                                ->first();
                        }
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

                TextColumn::make('six_month_review_history')
                    ->label('6-month review')
                    ->getStateUsing(fn ($record) => static::sixMonthReviewHistoryForAppointment($record))
                    ->formatStateUsing(fn ($state) => filled($state) ? $state : '—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                Filter::make('day')
                    ->label('Date')
                    ->form([
                        DatePicker::make('on')->label('On')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $on = $data['on'] ?? null;

                        if (! $on) {
                            return $query;
                        }

                        try {
                            $start = \Carbon\Carbon::parse($on, 'Europe/London')->startOfDay()->utc();
                            $end = \Carbon\Carbon::parse($on, 'Europe/London')->endOfDay()->utc();

                            return $query->whereBetween('start_at', [$start, $end]);
                        } catch (\Throwable $e) {
                            return $query->whereDate('start_at', $on);
                        }
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
                    ->url(fn ($record) => static::fastOrderDetailsUrlForRecord($record))
                    ->openUrlInNewTab(),

                \Filament\Actions\Action::make('addSixMonthReview')
                    ->label('Add 6-month review')
                    ->button()
                    ->color('warning')
                    ->icon('heroicon-o-calendar-days')
                    ->modalHeading('Add 6-month review')
                    ->modalSubmitActionLabel('Save review')
                    ->form([
                        ToggleButtons::make('height_unit')
                            ->label('Height units')
                            ->options([
                                'metric' => 'Metric',
                                'imperial' => 'Imperial',
                            ])
                            ->default('metric')
                            ->inline()
                            ->live(),

                        TextInput::make('height_cm')
                            ->label('Height (cm)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('height_unit') === 'metric'),

                        TextInput::make('height_ft')
                            ->label('Height (ft)')
                            ->numeric()
                            ->step('1')
                            ->visible(fn (Get $get) => $get('height_unit') === 'imperial'),

                        TextInput::make('height_in')
                            ->label('Height (in)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('height_unit') === 'imperial'),

                        ToggleButtons::make('weight_unit')
                            ->label('Weight units')
                            ->options([
                                'metric' => 'Metric',
                                'imperial' => 'Imperial',
                            ])
                            ->default('metric')
                            ->inline()
                            ->live(),

                        TextInput::make('weight_kg')
                            ->label('Weight (kg)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('weight_unit') === 'metric'),

                        TextInput::make('weight_st')
                            ->label('Weight (st)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('weight_unit') === 'imperial'),

                        TextInput::make('weight_lb')
                            ->label('Weight (lb)')
                            ->numeric()
                            ->step('0.1')
                            ->visible(fn (Get $get) => $get('weight_unit') === 'imperial'),

                        TextInput::make('bmi')
                            ->label('BMI')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('BMI will be calculated after saving'),

                        Textarea::make('review_text')
                            ->label('Review note')
                            ->rows(5)
                            ->placeholder('Optional note for this 6-month review.'),
                    ])
                    ->action(function ($record, array $data) {
                        if (! $record) {
                            return;
                        }

                        $userId = static::sixMonthReviewUserIdForAppointment($record);

                        if ($userId < 1) {
                            Notification::make()
                                ->danger()
                                ->title('Could not find patient')
                                ->send();

                            return;
                        }

                        $text = trim((string) ($data['review_text'] ?? ''));
                        $heightUnit = $data['height_unit'] ?? 'metric';
                        $weightUnit = $data['weight_unit'] ?? 'metric';

                        $heightCm = null;
                        $weightKg = null;

                        if ($heightUnit === 'metric') {
                            $heightCm = (float) ($data['height_cm'] ?? 0);
                        } else {
                            $ft = (float) ($data['height_ft'] ?? 0);
                            $in = (float) ($data['height_in'] ?? 0);
                            $heightCm = (($ft * 12) + $in) * 2.54;
                        }

                        if ($weightUnit === 'metric') {
                            $weightKg = (float) ($data['weight_kg'] ?? 0);
                        } else {
                            $st = (float) ($data['weight_st'] ?? 0);
                            $lb = (float) ($data['weight_lb'] ?? 0);
                            $weightKg = (($st * 14) + $lb) * 0.45359237;
                        }

                        $bmi = null;

                        if ($heightCm > 0 && $weightKg > 0) {
                            $heightM = $heightCm / 100;
                            $bmi = round($weightKg / ($heightM * $heightM), 1);
                        }

                        if ($text === '' && ! ($heightCm > 0) && ! ($weightKg > 0)) {
                            Notification::make()
                                ->danger()
                                ->title('Add height, weight or a note')
                                ->send();

                            return;
                        }

                        $sourceRecord = PendingOrder::query()
                            ->where('user_id', $userId)
                            ->whereNotNull('meta')
                            ->latest('id')
                            ->first();

                        if (! $sourceRecord) {
                            $sourceRecord = Order::query()
                                ->where('user_id', $userId)
                                ->whereNotNull('meta')
                                ->latest('id')
                                ->first();
                        }

                        if (! $sourceRecord) {
                            Notification::make()
                                ->danger()
                                ->title('Could not find an order to save the review')
                                ->send();

                            return;
                        }

                        $meta = is_array($sourceRecord->meta)
                            ? $sourceRecord->meta
                            : (json_decode($sourceRecord->meta ?? '[]', true) ?: []);

                        $reviews = data_get($meta, 'six_month_reviews', []);

                        if (! is_array($reviews)) {
                            $reviews = [];
                        }

                        $user = auth()->user();

                        $reviews[] = [
                            'date' => now()->toIso8601String(),
                            'height_unit' => $heightUnit,
                            'height_cm' => $heightCm ?: null,
                            'height_ft' => $data['height_ft'] ?? null,
                            'height_in' => $data['height_in'] ?? null,
                            'weight_unit' => $weightUnit,
                            'weight_kg' => $weightKg ?: null,
                            'weight_st' => $data['weight_st'] ?? null,
                            'weight_lb' => $data['weight_lb'] ?? null,
                            'bmi' => $bmi,
                            'text' => $text,
                            'by' => $user?->name ?: trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
                            'user_id' => auth()->id(),
                        ];

                        data_set($meta, 'six_month_reviews', $reviews);

                        $sourceRecord->meta = $meta;
                        $sourceRecord->save();

                        Notification::make()
                            ->success()
                            ->title('6-month review saved')
                            ->send();
                    }),

                   
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
                                // Updated logic to use displayStartAtFor
                                $dt = static::displayStartAtFor($record)?->copy()->tz('Europe/London');
                                if (! $dt) {
                                    return;
                                }
                                $set('start_date', $dt->format('Y-m-d'));
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
                                // Updated logic to use displayStartAtFor
                                $dt = static::displayStartAtFor($record)?->copy()->tz('Europe/London');
                                if (! $dt) {
                                    return;
                                }
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

                        $newStartAt = $data['start_at'] ?? null;
                        $newStartLondon = null;
                        $newStartUtc = null;

                        try {
                            if (is_string($newStartAt) && trim($newStartAt) !== '') {
                                $newStartLondon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', trim($newStartAt), 'Europe/London');
                                $newStartUtc = $newStartLondon->copy()->utc();
                            }
                        } catch (\Throwable $e) {
                            $newStartLondon = null;
                            $newStartUtc = null;
                        }

                        // 0) Resolve related order once using the OLD appointment time
                        //    so we do not lose the link when start_at changes.
                        $order = static::findRelatedOrder($record);

                        // 1) Update appointment start / end (default to 20 minutes if we don't have an existing duration)
                        $record->start_at = $newStartUtc?->format('Y-m-d H:i:s') ?? $data['start_at'];

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

                                $baseStart = $newStartUtc
                                    ? $newStartUtc->copy()
                                    : \Carbon\Carbon::parse($data['start_at'], 'Europe/London')->utc();

                                $record->end_at = $baseStart->copy()->addMinutes($duration)->format('Y-m-d H:i:s');
                            } catch (\Throwable $e) {
                                // If parsing fails, leave end_at as-is
                            }
                        }

                        $record->save();
                        static::cancelOtherActiveAppointmentsFor($record);
                        $record->refresh(); // make sure "When" column updates

                        // 1.5) If we successfully found an order, hard-link it to the appointment
                        //      and keep the order appointment datetime in sync with the reschedule.
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
                            static::cancelOtherActiveAppointmentsFor($record);
                            $record->refresh();

                            try {
                                $meta = is_array($order->meta)
                                    ? $order->meta
                                    : (json_decode($order->meta ?? '[]', true) ?: []);

                                if ($newStartLondon) {
                                    data_set($meta, 'appointment_at', $newStartLondon->copy()->toIso8601String());
                                    data_set($meta, 'appointment_start_at', $newStartLondon->copy()->toIso8601String());
                                    data_set($meta, 'appointment_start', $newStartLondon->copy()->toIso8601String());
                                    data_set($meta, 'appointment_datetime', $newStartLondon->copy()->toIso8601String());
                                    data_set($meta, 'appointmentDateTime', $newStartLondon->copy()->toIso8601String());
                                    data_set($meta, 'booking_date', $newStartLondon->copy()->format('Y-m-d'));
                                    data_set($meta, 'booking_time', $newStartLondon->copy()->format('H:i'));
                                    data_set($meta, 'consultation_date', $newStartLondon->copy()->format('Y-m-d'));
                                    data_set($meta, 'consultation_time', $newStartLondon->copy()->format('H:i'));
                                }

                                $update = [
                                    'meta' => $meta,
                                ];

                                try {
                                    if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'appointment_at') && $newStartUtc) {
                                        $update['appointment_at'] = $newStartUtc->copy()->format('Y-m-d H:i:s');
                                    }
                                } catch (\Throwable $e) {
                                    // ignore schema errors
                                }

                                $order->update($update);
                                $order->refresh();

                                \Log::info('appointment.rescheduled.order_synced', [
                                    'appointment_id' => $record->id ?? null,
                                    'order_id' => $order->id ?? null,
                                    'order_reference' => $order->reference ?? null,
                                    'appointment_start_at_utc' => $record->start_at ?? null,
                                    'order_appointment_at' => $order->appointment_at ?? null,
                                    'meta_appointment_at' => data_get($order->meta, 'appointment_at'),
                                ]);
                            } catch (\Throwable $e) {
                                \Log::warning('appointment.rescheduled.order_sync_failed', [
                                    'appointment_id' => $record->id ?? null,
                                    'order_id' => $order->id ?? null,
                                    'order_reference' => $order->reference ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                            }
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

                            $whenNew = $newStartLondon
                                ? $newStartLondon->copy()->format('d M Y, H:i')
                                : ($record->start_at
                                    ? \Carbon\Carbon::parse($record->start_at)->tz('Europe/London')->format('d M Y, H:i')
                                    : 'a new time');

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

                        \Log::info('appointment.rescheduled.completed', [
                            'appointment_id' => $record->id ?? null,
                            'order_id' => $order->id ?? null,
                            'order_reference' => $order->reference ?? null,
                            'old_start' => $oldStart,
                            'new_start_input' => $newStartAt,
                            'new_start_utc' => $record->start_at ?? null,
                            'new_start_london' => $newStartLondon?->format('Y-m-d H:i:s'),
                            'email' => $email,
                            'zoom_join_url' => $joinUrl,
                        ]);

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
        return parent::getEloquentQuery();
    }

   /*protected static function applyPendingOnlyAppointmentsConstraints(Builder $base): Builder
    {
        $pendingRefCol = null;
        try {
            $pendingRefCol = \Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'reference')
                ? 'reference'
                : (\Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'order_reference')
                    ? 'order_reference'
                    : (\Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'ref')
                        ? 'ref'
                        : null));
        } catch (\Throwable $e) {
            $pendingRefCol = null;
        }

        $hasApptReference = false;
        try {
            $hasApptReference = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'reference');
        } catch (\Throwable $e) {
            $hasApptReference = false;
        }

        $hasPendingOrderId = false;
        try {
            $hasPendingOrderId = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'pending_order_id');
        } catch (\Throwable $e) {
            $hasPendingOrderId = false;
        }

        $hasStatusCol = false;
        try {
            $hasStatusCol = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'status');
        } catch (\Throwable $e) {
            $hasStatusCol = false;
        }
        $hasOrderPaymentStatusCol = false;
        try {
            $hasOrderPaymentStatusCol = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status');
        } catch (\Throwable $e) {
            $hasOrderPaymentStatusCol = false;
        }

        return $base
            ->whereNotNull('start_at')
            ->when($hasStatusCol, function (Builder $q) {
                $q->where(function (Builder $qq) {
                    $qq->whereNull('status')
                        ->orWhere('status', '')
                        ->orWhereNotIn('status', ['completed', 'complete', 'done', 'cancelled', 'canceled', 'rejected']);
                });
            })
            ->where(function (Builder $q) use ($pendingRefCol, $hasApptReference, $hasPendingOrderId, $hasOrderPaymentStatusCol) {
                $q->orWhereExists(function ($sub) use ($hasOrderPaymentStatusCol) {
                    $sub->select(\DB::raw('1'))
                        ->from('orders')
                        ->whereColumn('orders.id', 'appointments.order_id')
                        ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')), '') != 'unpaid'")
                        ->when($hasOrderPaymentStatusCol, function ($qqq) {
                            $qqq->where(function ($q3) {
                                $q3->whereNull('orders.payment_status')
                                   ->orWhere('orders.payment_status', '!=', 'unpaid');
                            });
                        })
                        ->where(function ($qq) {
                            $qq->where('orders.status', 'pending')
                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')) = 'pending'")
                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label')) = 'Pending'");
                        });
                });

                // 4) Always include manually-created PCAO appointments
                $q->orWhere(function (Builder $qq) use ($hasApptReference) {
                    $qq->whereNull('appointments.order_id')
                        ->where(function (Builder $qq2) use ($hasApptReference) {
                            $qq2->where('appointments.order_reference', 'like', 'PCAO%');

                            if ($hasApptReference) {
                                $qq2->orWhere('appointments.reference', 'like', 'PCAO%');
                            }
                        });
                });

                $q->orWhereExists(function ($sub) use ($hasApptReference, $hasOrderPaymentStatusCol) {
                    $sub->select(\DB::raw('1'))
                        ->from('orders')
                        ->where(function ($qq) use ($hasApptReference) {
                            $qq->whereColumn('orders.reference', 'appointments.order_reference');

                            if ($hasApptReference) {
                                $qq->orWhereColumn('orders.reference', 'appointments.reference');
                            }
                        })
                        ->where(function ($qq) {
                            $qq->where('orders.status', 'pending')
                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')) = 'pending'")
                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label')) = 'Pending'");
                        })
                        ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')), '') != 'unpaid'")
                        ->when($hasOrderPaymentStatusCol, function ($qqq) {
                            $qqq->where(function ($q3) {
                                $q3->whereNull('orders.payment_status')
                                   ->orWhere('orders.payment_status', '!=', 'unpaid');
                            });
                        });
                });
            });
    }*/

    protected static function applyPendingOnlyAppointmentsConstraints(Builder $base): Builder
    {
        return $base
            ->whereNotNull('appointments.start_at')
            ->when(SchemaFacade::hasColumn('appointments', 'status'), function (Builder $query): void {
                $query->where(function (Builder $statusQuery): void {
                    $statusQuery
                        ->whereNull('appointments.status')
                        ->orWhere('appointments.status', '')
                        ->orWhereNotIn('appointments.status', [
                            'completed',
                            'complete',
                            'done',
                            'cancelled',
                            'canceled',
                            'rejected',
                        ]);
                });
            })
            ->where(function (Builder $query): void {
                // Linked by order_id: show while the order is still pending.
                $query->orWhereExists(function ($orders): void {
                    $orders
                        ->select(DB::raw('1'))
                        ->from('orders')
                        ->whereColumn('orders.id', 'appointments.order_id')
                        ->where(function ($statusQuery): void {
                            $statusQuery
                                ->where('orders.status', 'pending')
                                ->orWhere('orders.booking_status', 'pending');
                        })
                        ->where(function ($paymentQuery): void {
                            $paymentQuery
                                ->whereNull('orders.payment_status')
                                ->orWhere('orders.payment_status', '')
                                ->orWhere('orders.payment_status', '!=', 'unpaid');
                        });
                });

                // Linked by order_reference: show while the matching order is still pending.
                $query->orWhereExists(function ($orders): void {
                    $orders
                        ->select(DB::raw('1'))
                        ->from('orders')
                        ->whereColumn('orders.reference', 'appointments.order_reference')
                        ->where(function ($statusQuery): void {
                            $statusQuery
                                ->where('orders.status', 'pending')
                                ->orWhere('orders.booking_status', 'pending');
                        })
                        ->where(function ($paymentQuery): void {
                            $paymentQuery
                                ->whereNull('orders.payment_status')
                                ->orWhere('orders.payment_status', '')
                                ->orWhere('orders.payment_status', '!=', 'unpaid');
                        });
                });

                // Manual appointment-only bookings stay visible.
                $query->orWhere(function (Builder $manualQuery): void {
                    $manualQuery
                        ->whereNull('appointments.order_id')
                        ->where('appointments.order_reference', 'like', 'PCAO%');
                });
            });
    }

    public static function getNavigationBadge(): ?string
    {
        $pendingRefCol = null;
        try {
            $pendingRefCol = \Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'reference')
                ? 'reference'
                : (\Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'order_reference')
                    ? 'order_reference'
                    : (\Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'ref')
                        ? 'ref'
                        : null));
        } catch (\Throwable $e) {
            $pendingRefCol = null;
        }
        $hasApptReference = false;
        try {
            $hasApptReference = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'reference');
        } catch (\Throwable $e) {
            $hasApptReference = false;
        }
        $hasPendingOrderId = false;
        try {
            $hasPendingOrderId = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'pending_order_id');
        } catch (\Throwable $e) {
            $hasPendingOrderId = false;
        }
        $hasStatusCol = false;
        try {
            $hasStatusCol = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'status');
        } catch (\Throwable $e) {
            $hasStatusCol = false;
        }
        $hasOrderPaymentStatusCol = false;
        try {
            $hasOrderPaymentStatusCol = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status');
        } catch (\Throwable $e) {
            $hasOrderPaymentStatusCol = false;
        }
        $count = Appointment::query()
            ->whereNotNull('start_at')
            ->where('start_at', '>=', now('Europe/London')->startOfDay()->utc())
            ->when($hasStatusCol, function (Builder $q) {
                $q->where(function (Builder $qq) {
                    $qq->whereNull('status')
                       ->orWhere('status', '')
                       ->orWhereNotIn('status', ['completed', 'complete', 'done', 'cancelled', 'canceled', 'rejected']);
                });
            })
            ->where(function (Builder $q) use ($pendingRefCol, $hasApptReference, $hasPendingOrderId, $hasOrderPaymentStatusCol) {
                $q->orWhereExists(function ($sub) use ($hasOrderPaymentStatusCol) {
                    $sub->select(\DB::raw('1'))
                        ->from('orders')
                        ->whereColumn('orders.id', 'appointments.order_id')
                        ->where(function ($qq) {
                            $qq->where('orders.status', 'pending')
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')) = 'pending'")
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label')) = 'Pending'");
                        })
                        ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')), '') != 'unpaid'")
                        ->when($hasOrderPaymentStatusCol, function ($qqq) {
                            $qqq->where(function ($q3) {
                                $q3->whereNull('orders.payment_status')
                                   ->orWhere('orders.payment_status', '!=', 'unpaid');
                            });
                        });
                });

                $q->orWhereExists(function ($sub) use ($hasApptReference, $hasOrderPaymentStatusCol) {
                    $sub->select(\DB::raw('1'))
                        ->from('orders')
                        ->where(function ($qq) use ($hasApptReference) {
                            $qq->whereColumn('orders.reference', 'appointments.order_reference');

                            if ($hasApptReference) {
                                $qq->orWhereColumn('orders.reference', 'appointments.reference');
                            }
                        })
                        ->where(function ($qq) {
                            $qq->where('orders.status', 'pending')
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')) = 'pending'")
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label')) = 'Pending'");
                        })
                        ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')), '') != 'unpaid'")
                        ->when($hasOrderPaymentStatusCol, function ($qqq) {
                            $qqq->where(function ($q3) {
                                $q3->whereNull('orders.payment_status')
                                   ->orWhere('orders.payment_status', '!=', 'unpaid');
                            });
                        });
                });
            })
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $pendingRefCol = null;
        try {
            $pendingRefCol = \Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'reference')
                ? 'reference'
                : (\Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'order_reference')
                    ? 'order_reference'
                    : (\Illuminate\Support\Facades\Schema::hasColumn('pending_orders', 'ref')
                        ? 'ref'
                        : null));
        } catch (\Throwable $e) {
            $pendingRefCol = null;
        }
        $hasApptReference = false;
        try {
            $hasApptReference = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'reference');
        } catch (\Throwable $e) {
            $hasApptReference = false;
        }
        $hasPendingOrderId = false;
        try {
            $hasPendingOrderId = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'pending_order_id');
        } catch (\Throwable $e) {
            $hasPendingOrderId = false;
        }
        $hasStatusCol = false;
        try {
            $hasStatusCol = \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'status');
        } catch (\Throwable $e) {
            $hasStatusCol = false;
        }
        $hasOrderPaymentStatusCol = false;
        try {
            $hasOrderPaymentStatusCol = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status');
        } catch (\Throwable $e) {
            $hasOrderPaymentStatusCol = false;
        }
        $hasWaiting = Appointment::query()
            ->whereNotNull('start_at')
            ->where('start_at', '>=', now('Europe/London')->startOfDay()->utc())
            ->when($hasStatusCol, function (Builder $q) {
                $q->where(function (Builder $qq) {
                    $qq->whereNull('status')
                       ->orWhere('status', '')
                       ->orWhereNotIn('status', ['completed', 'complete', 'done', 'cancelled', 'canceled', 'rejected']);
                });
            })
            ->where(function (Builder $q) use ($pendingRefCol, $hasApptReference, $hasPendingOrderId, $hasOrderPaymentStatusCol) {
                $q->orWhereExists(function ($sub) use ($hasOrderPaymentStatusCol) {
                    $sub->select(\DB::raw('1'))
                        ->from('orders')
                        ->whereColumn('orders.id', 'appointments.order_id')
                        ->where(function ($qq) {
                            $qq->where('orders.status', 'pending')
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')) = 'pending'")
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label')) = 'Pending'");
                        })
                        ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')), '') != 'unpaid'")
                        ->when($hasOrderPaymentStatusCol, function ($qqq) {
                            $qqq->where(function ($q3) {
                                $q3->whereNull('orders.payment_status')
                                   ->orWhere('orders.payment_status', '!=', 'unpaid');
                            });
                        });
                });

                $q->orWhereExists(function ($sub) use ($hasApptReference, $hasOrderPaymentStatusCol) {
                    $sub->select(\DB::raw('1'))
                        ->from('orders')
                        ->where(function ($qq) use ($hasApptReference) {
                            $qq->whereColumn('orders.reference', 'appointments.order_reference');

                            if ($hasApptReference) {
                                $qq->orWhereColumn('orders.reference', 'appointments.reference');
                            }
                        })
                        ->where(function ($qq) {
                            $qq->where('orders.status', 'pending')
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')) = 'pending'")
                               ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label')) = 'Pending'");
                        })
                        ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status')), '') != 'unpaid'")
                        ->when($hasOrderPaymentStatusCol, function ($qqq) {
                            $qqq->where(function ($q3) {
                                $q3->whereNull('orders.payment_status')
                                   ->orWhere('orders.payment_status', '!=', 'unpaid');
                            });
                        });
                });
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
        if (! $a) return null;

        $s = static::displayStartAtFor($a);
        if (! $s) {
            return null;
        }

        $s = $s->copy()->tz('Europe/London');

        $e = null;
        try {
            if ($a->end_at) {
                $e = \Carbon\Carbon::parse($a->end_at, 'UTC')->tz('Europe/London');
            }
        } catch (\Throwable $e2) {
            $e = null;
        }

        return $e && $s->isSameDay($e)
            ? $s->format('d M Y, H:i') . ' — ' . $e->format('H:i')
            : $s->format('d M Y, H:i') . ($e ? ' — ' . $e->format('d M Y, H:i') : '');
    }

    protected static function displayStartAtFor($record): ?\Carbon\Carbon
    {
        if (! $record) {
            return null;
        }

        try {
            $raw = method_exists($record, 'getRawOriginal') ? $record->getRawOriginal('start_at') : $record->start_at;
            if (! $raw) {
                return null;
            }

            return \Carbon\Carbon::parse($raw, 'UTC');
        } catch (\Throwable $e) {
            return null;
        }
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

    public static function cancelOtherActiveAppointmentsFor($record): void
    {
        if (! $record || ! method_exists($record, 'getKey') || ! $record->getKey()) {
            return;
        }

        try {
            if (! SchemaFacade::hasColumn('appointments', 'status')) {
                return;
            }

            $orderId = $record->order_id ?? null;
            $orderReference = is_string($record->order_reference ?? null)
                ? trim((string) $record->order_reference)
                : '';

            if (! $orderId && $orderReference === '') {
                return;
            }

            $currentStatus = strtolower(trim((string) ($record->status ?? '')));
            $activeStatuses = ['pending', 'booked', 'approved', 'waiting'];

            if ($currentStatus !== '' && ! in_array($currentStatus, $activeStatuses, true)) {
                return;
            }

            Appointment::query()
                ->whereKeyNot($record->getKey())
                ->where(function (Builder $query) use ($orderId, $orderReference): void {
                    if ($orderId) {
                        $query->where('order_id', $orderId);
                    }

                    if ($orderReference !== '') {
                        $orderId
                            ? $query->orWhere('order_reference', $orderReference)
                            : $query->where('order_reference', $orderReference);
                    }
                })
                ->where(function (Builder $query): void {
                    $query->whereNull('status')
                        ->orWhere('status', '')
                        ->orWhereIn('status', [
                            'pending',
                            'booked',
                            'approved',
                            'waiting',
                        ]);
                })
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            \Log::warning('appointment.cancel_other_active_failed', [
                'appointment_id' => method_exists($record, 'getKey') ? $record->getKey() : null,
                'order_id' => $record->order_id ?? null,
                'order_reference' => $record->order_reference ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function findRelatedOrder($record): ?\App\Models\Order
    {
        if (! $record) return null;

        if (method_exists($record, 'relationLoaded') && $record->relationLoaded('order') && $record->order) {
            $order = $record->order;

            if (method_exists($order, 'loadMissing')) {
                $order->loadMissing('user');
            }

            return $order;
        }

        $cacheKey = $record->getKey();
        if (array_key_exists($cacheKey, static::$relatedOrderCache)) {
            return static::$relatedOrderCache[$cacheKey];
        }
        // 1) Direct link first
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('appointments', 'order_id') && !empty($record->order_id)) {
                $o = \App\Models\Order::query()
                    ->with('user')
                    ->find($record->order_id);

                static::$relatedOrderCache[$cacheKey] = $o;

                if ($o) {
                    return $o;
                }
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
                    ->with('user')
                    ->where('reference', $ref)
                    ->orderByDesc('id')
                    ->first();

                static::$relatedOrderCache[$cacheKey] = $o;

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
                        static::$relatedOrderCache[$cacheKey] = $ord;
                        return $ord;
                    }
                }
            }
        } catch (\Throwable $e) {}

        static::$relatedOrderCache[$cacheKey] = null;
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

    protected static function fastOrderDetailsUrlForRecord($record): ?string
    {
        if (! $record) {
            return null;
        }

        $reference = is_string($record->order_reference ?? null)
            ? trim((string) $record->order_reference)
            : '';

        if ($reference === '' && ! empty($record->order?->reference)) {
            $reference = trim((string) $record->order->reference);
        }

        if ($reference !== '') {
            return url('/admin/pending-orders?tableSearch=' . urlencode($reference));
        }

        if (! empty($record->order_id)) {
            return url('/admin/orders?tableSearch=' . urlencode((string) $record->order_id));
        }

        try {
            return static::getUrl('edit', ['record' => $record]);
        } catch (\Throwable $e) {
            return null;
        }
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

    public static function getGloballySearchableAttributes(): array
    {
        return [];
    }
}