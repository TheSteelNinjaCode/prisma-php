<?php
namespace PP\Security;

use PP\Headers\Boom;

final class RateLimiter
{
    /**
     * Lanza HTTP 429 si se supera el máximo de intentos en la ventana dada.
     *
     * @param string $key          Identificador único (p. ej. IP o user‑id).
     * @param int    $maxAttempts  Nº de peticiones permitidas.
     * @param int    $seconds      Ventana de tiempo en segundos.
     */
    public static function check(string $key, int $maxAttempts = 60, int $seconds = 60): void
    {
        if (!function_exists('apcu_fetch')) {
            // APCu no instalado: conviene registrar un “fallback” o lanzar excepción.
            Boom::internal("APCu extension missing for rate‑limit.")->toResponse();
        }

        $apcuKey = "ratelimit:{$key}";
        $current = apcu_fetch($apcuKey) ?: 0;

        if ($current >= $maxAttempts) {
            // HTTP 429 Too Many Requests
            Boom::tooManyRequests('Rate limit exceeded, try again later.')->toResponse();
        }

        // Incrementa contador y refresca TTL
        apcu_store($apcuKey, $current + 1, $seconds);
    }
}
