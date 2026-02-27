<?php

declare(strict_types=1);

namespace PP;

final class Env
{
    /**
     * Get raw env value (string) or null if missing.
     */
    public static function get(string $key): ?string
    {
        $v = getenv($key);
        if ($v !== false) {
            return $v;
        }

        $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($v === null) return null;

        // Normalize non-strings (rare but possible)
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_scalar($v)) return (string)$v;

        return null;
    }

    /**
     * Get string env with default.
     */
    public static function string(string $key, string $default = ''): string
    {
        $v = self::get($key);
        return ($v === null || $v === '') ? $default : $v;
    }

    /**
     * Get boolean env with default.
     * Accepts: true/false, 1/0, yes/no, on/off (case-insensitive)
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) return $default;

        $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $b ?? $default;
    }

    /**
     * Get int env with default.
     */
    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        if ($v === null) return $default;
        return is_numeric($v) ? (int)$v : $default;
    }
}
