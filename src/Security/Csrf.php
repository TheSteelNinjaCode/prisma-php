<?php

declare(strict_types=1);

namespace PP\Security;

use PP\Env;
use RuntimeException;

/**
 * The server half of the PulsePoint CSRF cookie.
 *
 * The runtime's RpcClient reads `pp_csrf_<port>` first (the port of the page
 * origin, i.e. the BrowserSync port in development) and falls back to
 * `pp_csrf`, then echoes the value back in the `X-CSRF-Token` header. The
 * value is a double-submit token signed with FUNCTION_CALL_SECRET:
 * `<nonce>.<hmac-sha256(nonce, secret)>`.
 *
 * In development both names are set, so the app validates whether it is
 * reached through the BrowserSync proxy or straight through the PHP server.
 */
final class Csrf
{
    private const BASE_COOKIE_NAME = 'pp_csrf';

    /** @var string[]|null */
    private static ?array $cookieNames = null;

    /**
     * Every cookie name this app issues, most specific first.
     *
     * @return string[]
     */
    public static function cookieNames(): array
    {
        if (self::$cookieNames !== null) {
            return self::$cookieNames;
        }

        $names = [];
        $scope = self::devCookieScope();
        if ($scope !== '') {
            $names[] = self::BASE_COOKIE_NAME . '_' . $scope;
        }
        $names[] = self::BASE_COOKIE_NAME;

        return self::$cookieNames = $names;
    }

    /**
     * Ensure a valid CSRF cookie exists for this response, minting one when
     * missing or invalid. Mirrors the former bootstrap `setCsrfCookie`.
     */
    public static function ensureCookie(): void
    {
        $secret = self::secret();

        foreach (self::cookieNames() as $name) {
            $existing = $_COOKIE[$name] ?? '';
            if ($existing !== '' && self::isValidToken($existing, $secret)) {
                // Every issued name must carry the same valid token so the
                // client may read whichever one matches its origin.
                self::setCookies($existing);
                return;
            }
        }

        self::setCookies(self::mintToken($secret));
    }

    /**
     * Mint and set a fresh token, e.g. after sign-in.
     */
    public static function rotate(): void
    {
        self::setCookies(self::mintToken(self::secret()));
    }

    /**
     * Validate the `X-CSRF-Token` header against the cookie jar.
     *
     * @return string|null An error message, or null when the token is valid.
     */
    public static function validateHeaderToken(string $headerToken): ?string
    {
        $secret = Env::string('FUNCTION_CALL_SECRET', '');
        if ($secret === '') {
            return 'CSRF secret is not configured';
        }

        if ($headerToken === '') {
            return 'Missing CSRF token';
        }

        $matchesCookie = false;
        foreach (self::cookieNames() as $name) {
            $cookieToken = $_COOKIE[$name] ?? '';
            if ($cookieToken !== '' && hash_equals($cookieToken, $headerToken)) {
                $matchesCookie = true;
                break;
            }
        }

        if (!$matchesCookie) {
            return 'Invalid CSRF token';
        }

        if (!self::isValidToken($headerToken, $secret)) {
            return 'Invalid CSRF token';
        }

        return null;
    }

    private static function secret(): string
    {
        $secret = Env::string('FUNCTION_CALL_SECRET', '');
        if ($secret === '') {
            throw new RuntimeException('FUNCTION_CALL_SECRET is required for CSRF protection.');
        }

        return $secret;
    }

    private static function mintToken(string $secret): string
    {
        $nonce = bin2hex(random_bytes(16));

        return $nonce . '.' . hash_hmac('sha256', $nonce, $secret);
    }

    private static function isValidToken(string $token, string $secret): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        [$nonce, $signature] = $parts;

        return hash_equals(hash_hmac('sha256', $nonce, $secret), $signature);
    }

    private static function setCookies(string $token): void
    {
        $options = [
            'expires'  => time() + 3600,
            'path'     => '/',
            'secure'   => self::isHttpsRequest(),
            'httponly' => false, // The runtime reads it from document.cookie.
            'samesite' => 'Lax',
        ];

        foreach (self::cookieNames() as $name) {
            if (!headers_sent()) {
                setcookie($name, $token, $options);
            }
            $_COOKIE[$name] = $token;
        }
    }

    /**
     * The development cookie scope: the BrowserSync port from
     * `settings/bs-config.json`, when the app is served locally. Matches the
     * runtime, which prefers `pp_csrf_<location.port>` over `pp_csrf`.
     */
    private static function devCookieScope(): string
    {
        if (Env::string('APP_ENV', 'production') === 'production') {
            return '';
        }

        if (!defined('SETTINGS_PATH')) {
            return '';
        }

        $configPath = SETTINGS_PATH . '/bs-config.json';
        if (!is_file($configPath)) {
            return '';
        }

        $config = json_decode((string) file_get_contents($configPath), true);
        $local = is_array($config) ? (string) ($config['local'] ?? '') : '';
        if ($local === '') {
            return '';
        }

        $parts = parse_url($local);
        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
            return '';
        }

        $port = $parts['port'] ?? null;

        return $port !== null ? (string) $port : '';
    }

    private static function isHttpsRequest(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
        );
    }
}
