<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class WeightLossOverview
{
    private const CACHE_SECONDS = 300;

    private const SERVICE_SLUGS = [
        'weight-management',
        'weight-loss-maintenance-plan',
    ];

    private const PRODUCT_NAME_SQL = <<<'SQL'
COALESCE(
    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.selectedProduct.name')), ''),
    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].name')), ''),
    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.lines[0].name')), '')
)
SQL;

    private const QUANTITY_SQL = <<<'SQL'
COALESCE(
    CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.selectedProduct.qty')) AS UNSIGNED),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].qty')) AS UNSIGNED),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.lines[0].qty')) AS UNSIGNED),
    1
)
SQL;

    private const RAW_VARIATION_SQL = <<<'SQL'
COALESCE(
    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.selectedProduct.strength')), ''), 'null'),
    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.selectedProduct.variation')), ''), 'null'),
    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].strength')), ''), 'null'),
    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].variation')), ''), 'null'),
    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].variations')), ''), 'null'),
    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.lines[0].variation')), ''), 'null'),
    'No variation'
)
SQL;

    private const TOTAL_MINOR_SQL = <<<'SQL'
COALESCE(
    CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.selectedProduct.totalMinor')) AS DECIMAL(12,2)),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].totalMinor')) AS DECIMAL(12,2)),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.totalMinor')) AS DECIMAL(12,2)),
    0
)
SQL;

    public static function summary(
        string $period = 'month',
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        [$start, $end] = self::range($period, $startDate, $endDate);
        $cacheRange = self::cacheRange($start, $end);

        return Cache::remember(
            "admin:weight-loss:summary:v2:{$period}:{$cacheRange}",
            self::CACHE_SECONDS,
            function () use ($start, $end): array {
                if (! self::available()) {
                    return self::emptySummary();
                }

                $name = self::PRODUCT_NAME_SQL;
                $qty = self::QUANTITY_SQL;
                $total = self::TOTAL_MINOR_SQL;
                $medication = self::medicationSql($name);
                $mounjaro = "LOWER({$name}) LIKE '%mounjaro%' OR LOWER({$name}) LIKE '%tirzepatide%'";
                $wegovy = "LOWER({$name}) LIKE '%wegovy%' OR LOWER({$name}) LIKE '%semaglutide%'";

                $row = self::paidOrders($start, $end)
                    ->selectRaw('COUNT(*) AS paid_orders')
                    ->selectRaw("COUNT(DISTINCT CASE WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) = 'new' THEN COALESCE(CAST(user_id AS CHAR), LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')))) END) AS new_patients")
                    ->selectRaw("SUM(CASE WHEN {$medication} THEN {$qty} ELSE 0 END) AS units")
                    ->selectRaw("SUM(CASE WHEN ({$mounjaro}) THEN {$qty} ELSE 0 END) AS mounjaro_units")
                    ->selectRaw("SUM(CASE WHEN ({$wegovy}) THEN {$qty} ELSE 0 END) AS wegovy_units")
                    ->selectRaw("SUM(CASE WHEN {$medication} THEN {$total} ELSE 0 END) / 100 AS revenue")
                    ->first();

                return [
                    'paid_orders' => (int) ($row->paid_orders ?? 0),
                    'new_patients' => (int) ($row->new_patients ?? 0),
                    'units' => (int) ($row->units ?? 0),
                    'mounjaro_units' => (int) ($row->mounjaro_units ?? 0),
                    'wegovy_units' => (int) ($row->wegovy_units ?? 0),
                    'revenue' => (float) ($row->revenue ?? 0),
                ];
            }
        );
    }

    public static function trend(
        int $months = 6,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $months = in_array($months, [3, 6, 12], true) ? $months : 6;
        $customRange = self::customRange($startDate, $endDate);
        $cacheRange = $customRange
            ? self::cacheRange($customRange[0], $customRange[1])
            : "quick-{$months}";

        return Cache::remember(
            "admin:weight-loss:trend:v5:{$cacheRange}",
            self::CACHE_SECONDS,
            function () use ($months, $customRange): array {
                $now = CarbonImmutable::now('Europe/London');
                $start = $customRange[0] ?? $now->startOfMonth()->subMonths($months - 1)->utc();
                $end = $customRange[1] ?? $now->endOfMonth()->utc();
                $labels = [];
                $keys = [];
                $useDays = $customRange && $start->diffInDays($end) <= 45;

                if ($useDays) {
                    $cursor = $start->setTimezone('Europe/London')->startOfDay();
                    $last = $end->setTimezone('Europe/London')->startOfDay();

                    while ($cursor->lte($last)) {
                        $labels[] = $cursor->format('d M');
                        $keys[] = $cursor->format('Y-m-d');
                        $cursor = $cursor->addDay();
                    }
                } else {
                    $cursor = $start->setTimezone('Europe/London')->startOfMonth();
                    $last = $end->setTimezone('Europe/London')->startOfMonth();

                    while ($cursor->lte($last)) {
                        $labels[] = $cursor->format('M Y');
                        $keys[] = $cursor->format('Y-m');
                        $cursor = $cursor->addMonth();
                    }
                }

                if (! self::available()) {
                    return self::emptyTrend($labels);
                }

                $name = self::PRODUCT_NAME_SQL;
                $qty = self::QUANTITY_SQL;
                $productNames = array_values(self::productOptions());
                $dateKeySql = $useDays
                    ? "DATE_FORMAT(paid_at, '%Y-%m-%d')"
                    : "DATE_FORMAT(paid_at, '%Y-%m')";

                $productRows = self::paidOrders($start, $end)
                    ->when(
                        $productNames !== [],
                        fn (Builder $query) => $query->whereIn(DB::raw($name), $productNames),
                        fn (Builder $query) => $query->whereRaw('1 = 0'),
                    )
                    ->selectRaw("{$dateKeySql} AS date_key")
                    ->selectRaw("{$name} AS product")
                    ->selectRaw("SUM({$qty}) AS units")
                    ->groupBy('date_key', 'product')
                    ->orderBy('product')
                    ->get();

                $patientRows = self::paidOrders($start, $end)
                    ->selectRaw("{$dateKeySql} AS date_key")
                    ->selectRaw("COUNT(DISTINCT CASE WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) = 'new' THEN COALESCE(CAST(user_id AS CHAR), LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')))) END) AS new_patients")
                    ->groupBy('date_key')
                    ->get()
                    ->keyBy('date_key');

                $series = [];
                foreach ($productRows as $row) {
                    $label = self::chartProductLabel((string) ($row->product ?? 'Unknown medication'));
                    $dateKey = (string) $row->date_key;
                    $series[$label][$dateKey] = ($series[$label][$dateKey] ?? 0) + (int) $row->units;
                }

                $products = [];
                foreach ($series as $label => $values) {
                    $products[] = [
                        'label' => $label,
                        'data' => array_map(
                            static fn (string $key): int => (int) ($values[$key] ?? 0),
                            $keys
                        ),
                    ];
                }

                return [
                    'labels' => $labels,
                    'products' => $products,
                    'new_patients' => self::series($patientRows, $keys, 'new_patients'),
                ];
            }
        );
    }

    public static function productBreakdown(
        string $period = 'month',
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $productSlug = null,
    ): Collection {
        [$start, $end] = self::range($period, $startDate, $endDate);
        $cacheRange = self::cacheRange($start, $end);
        $products = self::productOptions();
        $productSlug = array_key_exists((string) $productSlug, $products)
            ? (string) $productSlug
            : array_key_first($products);
        $productName = $productSlug ? ($products[$productSlug] ?? null) : null;

        return Cache::remember(
            'admin:weight-loss:products:v6:'.$period.':'.$cacheRange.':'.sha1((string) $productSlug),
            self::CACHE_SECONDS,
            function () use ($start, $end, $productSlug, $productName): Collection {
                if (! self::available() || ! $productSlug || ! $productName) {
                    return collect();
                }

                $name = self::PRODUCT_NAME_SQL;
                $variation = self::variationSql();
                $qty = self::QUANTITY_SQL;
                $total = self::TOTAL_MINOR_SQL;

                return self::paidOrders($start, $end)
                    ->where(function (Builder $query) use ($name, $productSlug, $productName): void {
                        $query
                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].sku')) = ?", [$productSlug])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.items[0].sku')) LIKE ?", [$productSlug.':%'])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.selectedProduct.slug')) = ?", [$productSlug])
                            ->orWhereRaw("{$name} = ?", [$productName]);
                    })
                    ->selectRaw("{$variation} AS variation")
                    ->selectRaw('COUNT(*) AS orders_count')
                    ->selectRaw("SUM({$qty}) AS units")
                    ->selectRaw("SUM({$total}) / 100 AS revenue")
                    ->groupBy('variation')
                    ->orderByDesc('units')
                    ->orderBy('variation')
                    ->get()
                    ->map(fn (object $row): array => [
                        'product' => $productName,
                        'variation' => (string) ($row->variation ?? 'No variation'),
                        'orders' => (int) ($row->orders_count ?? 0),
                        'units' => (int) ($row->units ?? 0),
                        'revenue' => (float) ($row->revenue ?? 0),
                    ]);
            }
        );
    }

    public static function treatmentPerformance(
        string $period = 'six_months',
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        [$start, $end] = self::range($period, $startDate, $endDate);
        $cacheRange = self::cacheRange($start, $end);

        return Cache::remember(
            "admin:weight-loss:performance:v2:{$cacheRange}",
            self::CACHE_SECONDS,
            function () use ($start, $end): array {
                $empty = self::emptyTreatmentPerformance();
                if (! self::available()) {
                    return $empty;
                }

                $name = self::PRODUCT_NAME_SQL;
                $rows = DB::table('orders')
                    ->where('payment_status', 'paid')
                    ->whereNotNull('paid_at')
                    ->where('paid_at', '<=', $end)
                    ->whereIn(
                        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service_slug'))"),
                        self::SERVICE_SLUGS,
                    )
                    ->whereNotNull(DB::raw($name))
                    ->select(['id', 'user_id', 'paid_at'])
                    ->selectRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email'))) AS patient_email")
                    ->selectRaw("{$name} AS product")
                    ->selectRaw("COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(meta, '$.formsQA.raf.answers.weight')),
                        JSON_UNQUOTE(JSON_EXTRACT(meta, '$.formsQA.assessment.answers.weight')),
                        JSON_UNQUOTE(JSON_EXTRACT(meta, '$.weight'))
                    ) AS recorded_weight")
                    ->orderBy('paid_at')
                    ->orderBy('id')
                    ->get();

                $readings = [];
                foreach ($rows as $row) {
                    $category = self::treatmentCategory((string) ($row->product ?? ''));
                    $weight = self::weightInKg($row->recorded_weight ?? null);
                    $patient = $row->user_id
                        ? 'user:'.$row->user_id
                        : 'email:'.($row->patient_email ?? '');

                    if (! $category || ! $weight || $patient === 'email:') {
                        continue;
                    }

                    $readings[$category][$patient][] = [
                        'at' => CarbonImmutable::parse($row->paid_at, 'UTC'),
                        'weight' => $weight,
                    ];
                }

                foreach ($empty as $category => $defaults) {
                    $changes = [];
                    $percentages = [];

                    foreach ($readings[$category] ?? [] as $patientReadings) {
                        $withinRange = array_values(array_filter(
                            $patientReadings,
                            static fn (array $reading): bool => $reading['at']->betweenIncluded($start, $end),
                        ));

                        if ($withinRange === []) {
                            continue;
                        }

                        $latest = end($withinRange);
                        $beforeRange = array_values(array_filter(
                            $patientReadings,
                            static fn (array $reading): bool => $reading['at']->lt($start),
                        ));
                        $baseline = $beforeRange !== [] ? end($beforeRange) : $withinRange[0];

                        if (! $baseline || ! $latest || $baseline['at']->gte($latest['at'])) {
                            continue;
                        }

                        $change = $baseline['weight'] - $latest['weight'];
                        $changes[] = $change;
                        $percentages[] = $baseline['weight'] > 0
                            ? ($change / $baseline['weight']) * 100
                            : 0;
                    }

                    if ($changes === []) {
                        continue;
                    }

                    $patientCount = count($changes);
                    $losingCount = count(array_filter($changes, static fn (float $change): bool => $change > 0));

                    $empty[$category] = [
                        'patients' => $patientCount,
                        'average_loss_kg' => round(array_sum($changes) / $patientCount, 1),
                        'average_loss_percent' => round(array_sum($percentages) / $patientCount, 1),
                        'losing_percent' => (int) round(($losingCount / $patientCount) * 100),
                    ];
                }

                return $empty;
            },
        );
    }

    private static function paidOrders(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('orders')
            ->where('payment_status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->whereIn(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service_slug'))"),
                self::SERVICE_SLUGS,
            );
    }

    public static function productOptions(): array
    {
        if (! DatabaseSchema::hasTable('products')
            || ! DatabaseSchema::hasTable('services')
            || ! DatabaseSchema::hasTable('product_service')) {
            return [];
        }

        return Cache::remember('admin:weight-loss:product-options:v2', self::CACHE_SECONDS, function (): array {
            return DB::table('products')
                ->join('product_service', 'product_service.product_id', '=', 'products.id')
                ->join('services', 'services.id', '=', 'product_service.service_id')
                ->whereIn('services.slug', self::SERVICE_SLUGS)
                ->where('product_service.active', true)
                ->where('products.status', 'published')
                ->selectRaw('products.slug, products.name, MIN(product_service.sort_order) AS product_sort, MIN(products.id) AS product_id')
                ->groupBy('products.slug', 'products.name')
                ->orderBy('product_sort')
                ->orderBy('product_id')
                ->pluck('products.name', 'products.slug')
                ->all();
        });
    }

    private static function range(
        string $period,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        if ($customRange = self::customRange($startDate, $endDate)) {
            return $customRange;
        }

        $now = CarbonImmutable::now('Europe/London');
        $start = match ($period) {
            'quarter' => $now->startOfDay()->subMonths(3),
            'six_months' => $now->startOfDay()->subMonths(6),
            'year' => $now->startOfDay()->subYear(),
            default => $now->startOfMonth(),
        };

        return [$start->utc(), $now->endOfDay()->utc()];
    }

    private static function customRange(?string $startDate, ?string $endDate): ?array
    {
        if (! $startDate && ! $endDate) {
            return null;
        }

        try {
            $today = CarbonImmutable::now('Europe/London');
            $end = $endDate
                ? CarbonImmutable::parse($endDate, 'Europe/London')->endOfDay()
                : $today->endOfDay();
            $start = $startDate
                ? CarbonImmutable::parse($startDate, 'Europe/London')->startOfDay()
                : $end->startOfMonth();

            if ($start->gt($end)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }

            return [$start->utc(), $end->utc()];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cacheRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format('Ymd').'-'.$end->format('Ymd');
    }

    private static function medicationSql(string $name): string
    {
        return "LOWER({$name}) REGEXP 'mounjaro|tirzepatide|wegovy|semaglutide|saxenda|liraglutide|orlistat|xenical|foundayo|orforglipron'";
    }

    private static function variationSql(): string
    {
        $variation = self::RAW_VARIATION_SQL;

        return "CASE
            WHEN LOWER({$variation}) REGEXP '[0-9]+(\\\\.[0-9]+)?[[:space:]]*mg'
                THEN CONCAT(REGEXP_SUBSTR(LOWER({$variation}), '[0-9]+(\\\\.[0-9]+)?'), 'mg')
            ELSE {$variation}
        END";
    }

    private static function available(): bool
    {
        return DatabaseSchema::hasTable('orders')
            && DatabaseSchema::hasColumn('orders', 'meta')
            && DatabaseSchema::hasColumn('orders', 'paid_at')
            && DatabaseSchema::hasColumn('orders', 'payment_status');
    }

    private static function series(Collection $rows, array $keys, string $field): array
    {
        return array_map(
            static fn (string $key): int => (int) ($rows->get($key)?->{$field} ?? 0),
            $keys
        );
    }

    private static function emptySummary(): array
    {
        return array_fill_keys([
            'paid_orders', 'new_patients', 'units', 'mounjaro_units', 'wegovy_units', 'revenue',
        ], 0);
    }

    private static function emptyTrend(array $labels): array
    {
        $zeros = array_fill(0, count($labels), 0);

        return compact('labels') + [
            'products' => [],
            'new_patients' => $zeros,
        ];
    }

    private static function productVariationLabel(string $product, string $variation): string
    {
        if ($variation === '' || strtolower($variation) === 'no variation') {
            return $product;
        }

        return "{$product} — {$variation}";
    }

    private static function chartProductLabel(string $product): string
    {
        $label = preg_replace('/\s*\([^)]*\).*$/', '', $product);

        return trim((string) ($label ?: $product));
    }

    private static function treatmentCategory(string $product): ?string
    {
        $product = strtolower($product);

        if (str_contains($product, 'mounjaro') || str_contains($product, 'tirzepatide')) {
            return 'mounjaro';
        }

        if (str_contains($product, 'wegovy') && str_contains($product, 'pill')) {
            return 'wegovy_pill';
        }

        if (str_contains($product, 'wegovy')
            && (str_contains($product, 'oral') || str_contains($product, 'tablet'))) {
            return 'wegovy_pill';
        }

        if (str_contains($product, 'wegovy') || str_contains($product, 'semaglutide')) {
            return 'wegovy_injection';
        }

        if (str_contains($product, 'foundayo') || str_contains($product, 'orforglipron')) {
            return 'foundayo';
        }

        return null;
    }

    private static function weightInKg(mixed $value): ?float
    {
        if (is_numeric($value)) {
            $weight = (float) $value;

            return $weight > 0 ? $weight : null;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === 'null') {
            return null;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*st(?:one)?s?(?:\s*(\d+(?:\.\d+)?)\s*lb)?/', $value, $matches)) {
            $pounds = ((float) $matches[1] * 14) + (float) ($matches[2] ?? 0);

            return round($pounds * 0.45359237, 2);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:lb|lbs|pounds?)/', $value, $matches)) {
            return round((float) $matches[1] * 0.45359237, 2);
        }

        if (preg_match('/(\d+(?:\.\d+)?)/', $value, $matches)) {
            $weight = (float) $matches[1];

            return $weight > 0 ? $weight : null;
        }

        return null;
    }

    private static function emptyTreatmentPerformance(): array
    {
        $empty = [
            'patients' => 0,
            'average_loss_kg' => null,
            'average_loss_percent' => null,
            'losing_percent' => null,
        ];

        return [
            'mounjaro' => $empty,
            'wegovy_injection' => $empty,
            'wegovy_pill' => $empty,
            'foundayo' => $empty,
        ];
    }
}
