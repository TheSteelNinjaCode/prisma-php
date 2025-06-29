<?php

declare(strict_types=1);

namespace PPHP;

use PPHP\PrismaPHPSettings;

class CacheHandler
{
    public static bool $isCacheable = true; // Enable or disable caching by route
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
