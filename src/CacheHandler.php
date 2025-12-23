<?php

declare(strict_types=1);

namespace PP;

use PP\PrismaPHPSettings;

class CacheHandler
{
    public static ?bool $isCacheable = null; // null = follow global, true = force cache, false = force no cache
    public static int $ttl = 0; // Time to live in seconds (0 = no action taken)

    private static string $cacheDir = DOCUMENT_PATH . '/caches';
    private static bool $cacheDirChecked = false;

    private static function ensureCacheDirectoryExists(): void
    {
        if (self::$cacheDirChecked) {
            return;
        }

        if (!is_dir(self::$cacheDir) && !mkdir(self::$cacheDir, 0777, true) && !is_dir(self::$cacheDir)) {
            die("Error: Unable to create cache directory at: " . self::$cacheDir);
        }

        self::$cacheDirChecked = true;
    }

    public static function getCacheFilePath(string $uri): string
    {
        $requestFilesData = PrismaPHPSettings::$includeFiles;
        $fileName = $requestFilesData[$uri]['fileName'] ?? '';
        $isCacheable = $requestFilesData[$uri]['isCacheable'] ?? self::$isCacheable;

        if (!$isCacheable || $fileName === '') {
            return '';
        }

        return self::$cacheDir . '/' . $fileName . '.html';
    }

    private static function isExpired(string $cacheFile, int $ttlSeconds = 600): bool
    {
        if (!file_exists($cacheFile)) {
            return true;
        }
        $fileAge = time() - filemtime($cacheFile);
        return $fileAge > $ttlSeconds;
    }

    /**
     * Serve cache if available, not expired, and route is marked cacheable.
     * We look up a route-specific TTL if defined, otherwise use a fallback.
     */
    public static function serveCache(string $uri, int $defaultTtl = 600): void
    {
        if ($uri === '') {
            return;
        }

        $requestFilesData = PrismaPHPSettings::$includeFiles;

        // Get the route-specific TTL if set, or default to 0
        $routeTtl = $requestFilesData[$uri]['cacheTtl'] ?? 0;

        // If the route has a TTL greater than 0, use that.
        // Otherwise (0 or not defined), use the default.
        $ttlSeconds = ($routeTtl > 0) ? $routeTtl : $defaultTtl;

        $cacheFile = self::getCacheFilePath($uri);

        if ($cacheFile === '' || !file_exists($cacheFile) || self::isExpired($cacheFile, $ttlSeconds)) {
            return;
        }

        echo "<!-- Cached copy generated at: " . date('Y-m-d H:i:s', filemtime($cacheFile)) . " -->\n";
        readfile($cacheFile);
        exit;
    }

    public static function saveCache(string $uri, string $content, bool $useLock = true): void
    {
        if ($uri === '') {
            return;
        }
        self::ensureCacheDirectoryExists();

        $cacheFile = self::getCacheFilePath($uri);
        if ($cacheFile === '') {
            return;
        }

        $flags = $useLock ? LOCK_EX : 0;
        $written = @file_put_contents($cacheFile, $content, $flags);

        if ($written === false) {
            die("Error: Failed to write cache file: $cacheFile");
        }
    }

    /**
     * Invalidate cache for multiple routes by URI.
     * 
     * Accepts URIs with or without leading slash - both formats work.
     * Use this when you need to clear cache for specific routes after data changes.
     * 
     * @param string|array $uris Single URI or array of URIs to invalidate
     * 
     * @example
     * Single route
     * CacheHandler::invalidateByUri('/users');
     * 
     * Multiple routes
     * CacheHandler::invalidateByUri(['/', '/users', '/dashboard']);
     * 
     * After updating user data
     * function updateUser($data) {
     *  ... update logic
     *     CacheHandler::invalidateByUri(['/', '/users', '/user-profile']);
     *     return $result;
     * }
     */
    public static function invalidateByUri(string|array $uris): void
    {
        $uris = is_array($uris) ? $uris : [$uris];

        foreach ($uris as $uri) {
            $normalizedUri = ltrim($uri, '/');
            if ($normalizedUri === '') {
                $normalizedUri = '/';
            }
            self::resetCache($normalizedUri);
        }
    }

    /**
     * Reset/clear cache files.
     * 
     * @param string|null $uri If provided, clears only that route's cache. 
     *                         If null, clears ALL cache files.
     * 
     * @example
     * Clear specific route
     * CacheHandler::resetCache('/users');
     * 
     * Clear all cache
     * CacheHandler::resetCache();
     */
    public static function resetCache(?string $uri = null): void
    {
        self::ensureCacheDirectoryExists();

        if ($uri !== null) {
            $cacheFile = self::getCacheFilePath($uri);
            if ($cacheFile !== '' && file_exists($cacheFile)) {
                unlink($cacheFile);
            }
            return;
        }

        $files = glob(self::$cacheDir . '/*.html') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
