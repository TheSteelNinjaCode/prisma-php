<?php

declare(strict_types=1);

namespace PPHP\Middleware;

final class CorsMiddleware
{
    /** Entry point */
    public static function handle(?array $overrides = null): void
    {
        // Not a CORS request
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return;
        }

        // Resolve config (env → overrides)
        $cfg = self::buildConfig($overrides);

        // Not allowed? Do nothing (browser will block)
        if (!self::isAllowedOrigin($origin, $cfg['allowedOrigins'])) {
            return;
        }

        // Compute which value to send for Access-Control-Allow-Origin
        // If credentials are disabled and '*' is in list, we can send '*'
        $sendWildcard = (!$cfg['allowCredentials'] && self::listHasWildcard($cfg['allowedOrigins']));
        $allowOriginValue = $sendWildcard ? '*' : self::normalize($origin);

        // Vary for caches
        header('Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers');

        header('Access-Control-Allow-Origin: ' . $allowOriginValue);
        if ($cfg['allowCredentials']) {
            header('Access-Control-Allow-Credentials: true');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // Preflight response
            $requestedHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
            $allowedHeaders = $cfg['allowedHeaders'] !== ''
                ? $cfg['allowedHeaders']
                : ($requestedHeaders ?: 'Content-Type, Authorization, X-Requested-With');

            header('Access-Control-Allow-Methods: ' . $cfg['allowedMethods']);
            header('Access-Control-Allow-Headers: ' . $allowedHeaders);
            if ($cfg['maxAge'] > 0) {
                header('Access-Control-Max-Age: ' . (string) $cfg['maxAge']);
            }

            // Optional: Private Network Access preflights (Chrome)
            if (!empty($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'])) {
                header('Access-Control-Allow-Private-Network: true');
            }

            http_response_code(204);
            header('Content-Length: 0');
            exit;
        }

        // Simple/actual request
        if ($cfg['exposeHeaders'] !== '') {
            header('Access-Control-Expose-Headers: ' . $cfg['exposeHeaders']);
        }
    }

    /** Read env + normalize + apply overrides */
    private static function buildConfig(?array $overrides): array
    {
        $allowed = self::parseList($_ENV['CORS_ALLOWED_ORIGINS'] ?? '');
        $cfg = [
            'allowedOrigins'   => $allowed,
            'allowCredentials' => filter_var($_ENV['CORS_ALLOW_CREDENTIALS'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'allowedMethods'   => $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'allowedHeaders'   => trim($_ENV['CORS_ALLOWED_HEADERS'] ?? ''),
            'exposeHeaders'    => trim($_ENV['CORS_EXPOSE_HEADERS'] ?? ''),
            'maxAge'           => (int)($_ENV['CORS_MAX_AGE'] ?? 86400),
        ];

        if (is_array($overrides)) {
            foreach ($overrides as $k => $v) {
                if (array_key_exists($k, $cfg)) {
                    $cfg[$k] = $v;
                }
            }
        }

        // Normalize patterns
        $cfg['allowedOrigins'] = array_map([self::class, 'normalize'], $cfg['allowedOrigins']);
        return $cfg;
    }

    /** CSV or JSON array → array<string> */
    private static function parseList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];

        if ($raw[0] === '[') {
            $arr = json_decode($raw, true);
            if (is_array($arr)) {
                return array_values(array_filter(array_map('strval', $arr), 'strlen'));
            }
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    private static function normalize(string $origin): string
    {
        return rtrim($origin, '/');
    }

    private static function isAllowedOrigin(string $origin, array $list): bool
    {
        $o = self::normalize($origin);

        foreach ($list as $pattern) {
            $p = self::normalize($pattern);

            // literal "*"
            if ($p === '*') return true;

            // allow literal "null" for file:// or sandboxed if explicitly listed
            if ($o === 'null' && strtolower($p) === 'null') return true;

            // wildcard like https://*.example.com
            if (strpos($p, '*') !== false) {
                $regex = '/^' . str_replace('\*', '[^.]+', preg_quote($p, '/')) . '$/i';
                if (preg_match($regex, $o)) return true;
            } else {
                if (strcasecmp($p, $o) === 0) return true;
            }
        }
        return false;
    }

    private static function listHasWildcard(array $list): bool
    {
        foreach ($list as $p) {
            if (trim($p) === '*') return true;
        }
        return false;
    }
}
