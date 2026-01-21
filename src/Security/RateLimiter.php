<?php

namespace PP\Security;

use PP\Headers\Boom;

final class RateLimiter
{
    /**
     * Throws an HTTP 429 if the maximum number of attempts within the given window is exceeded.
     *
     * @param string $key         Unique identifier (e.g., IP or user-id).
     * @param int    $maxAttempts Number of allowed requests.
     * @param int    $seconds     Time window in seconds.
     */
    public static function check(string $key, int $maxAttempts = 60, int $seconds = 60): void
    {
        if (!function_exists('apcu_fetch')) {
            // APCu not installed: consider registering a fallback or throwing an exception.
            Boom::internalServerError("APCu extension missing for rate‑limit.")->toResponse();
        }

        $apcuKey = "ratelimit:{$key}";
        $current = apcu_fetch($apcuKey) ?: 0;

        if ($current >= $maxAttempts) {
            // HTTP 429 Too Many Requests
            Boom::tooManyRequests('Rate limit exceeded, try again later.')->toResponse();
        }

        // Increment counter and refresh TTL
        apcu_store($apcuKey, $current + 1, $seconds);
    }
}
