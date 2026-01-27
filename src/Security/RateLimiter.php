<?php

namespace PP\Security;

use PP\Headers\Boom;
use RuntimeException;

final class RateLimiter
{
    /**
     * Enforces a set of rate limit policies on a specific key.
     *
     * @param string $identifier Unique identifier for the action (e.g., function name).
     * @param string|array $limits Array of limit strings (e.g., ["60/m", "1000/d"]).
     */
    public static function verify(string $identifier, string|array $limits): void
    {
        // Use client IP as the user identifier
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $baseKey = "rpc_limit:{$identifier}:{$clientIp}";

        $policies = is_array($limits) ? $limits : [$limits];

        foreach ($policies as $policy) {
            if (empty($policy)) continue;

            [$maxAttempts, $windowSeconds] = self::parsePolicy($policy);

            // Create a unique key for this specific policy variation
            $policyKey = "{$baseKey}:{$maxAttempts}:{$windowSeconds}";

            self::check($policyKey, $maxAttempts, $windowSeconds);
        }
    }

    /**
     * Parses a limit string (e.g. "60/minute") into [count, seconds].
     */
    private static function parsePolicy(string $policy): array
    {
        $parts = explode('/', $policy, 2);
        if (count($parts) !== 2) {
            // Fallback default if format is invalid
            return [60, 60];
        }

        $count = (int)$parts[0];
        $periodStr = strtolower(trim($parts[1]));
        $seconds = 60; // default

        // Match standard units
        if (in_array($periodStr, ['second', 'sec', 's'])) $seconds = 1;
        elseif (in_array($periodStr, ['minute', 'min', 'm'])) $seconds = 60;
        elseif (in_array($periodStr, ['hour', 'h'])) $seconds = 3600;
        elseif (in_array($periodStr, ['day', 'd'])) $seconds = 86400;
        // Match complex units (e.g., "1h", "30s")
        elseif (preg_match('/^(\d+)\s*([a-z]+)$/', $periodStr, $matches)) {
            $val = (int)$matches[1];
            $unit = $matches[2];
            if (str_starts_with($unit, 'd')) $seconds = $val * 86400;
            elseif (str_starts_with($unit, 'h')) $seconds = $val * 3600;
            elseif (str_starts_with($unit, 'm')) $seconds = $val * 60;
            elseif (str_starts_with($unit, 's')) $seconds = $val;
        }

        return [$count, $seconds];
    }

    /**
     * Original check method (modified slightly to throw RuntimeException for consistency with bootstrap)
     */
    public static function check(string $key, int $maxAttempts = 60, int $seconds = 60): void
    {
        if (!function_exists('apcu_fetch')) {
            throw new RuntimeException("APCu extension missing for rate-limit.");
        }

        // Use a sliding window bucket logic or simple fixed window
        $current = (int)apcu_fetch($key);

        if ($current >= $maxAttempts) {
            // Throwing exception which bootstrap will catch and return as JSON error
            throw new RuntimeException("Rate limit exceeded. Try again later.");
        }

        // Increment. If key doesn't exist, create it with TTL.
        if ($current === 0) {
            apcu_add($key, 1, $seconds);
        } else {
            apcu_inc($key);
        }
    }
}
