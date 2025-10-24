<?php

namespace App\Support;

class Settings
{
    protected static string $file = 'app_settings.json';

    protected static function path(): string
    {
        return storage_path(self::$file);
    }

    public static function all(): array
    {
        $path = self::path();
        if (! file_exists($path)) return [];

        $json = file_get_contents($path);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::all();
        return $data[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $data = self::all();
        $data[$key] = $value;
        @file_put_contents(self::path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}