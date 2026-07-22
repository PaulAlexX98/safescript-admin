<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Process-local cache for immutable database schema capability checks.
 *
 * Dashboard widgets ask the same hasTable()/hasColumn() questions many times
 * during one render. Schema changes are deployed with a PHP worker restart, so
 * retaining these answers for the worker lifetime is safe and avoids repeated
 * information_schema queries.
 */
final class DatabaseSchema
{
    /** @var array<string, bool> */
    private static array $tables = [];

    /** @var array<string, bool> */
    private static array $columns = [];

    public static function hasTable(string $table): bool
    {
        return self::$tables[$table] ??= Schema::hasTable($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        return self::$columns[$key] ??= Schema::hasColumn($table, $column);
    }

    public static function flush(): void
    {
        self::$tables = [];
        self::$columns = [];
    }
}
