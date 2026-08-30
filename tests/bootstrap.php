<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/PrismaPHPSettings.php';

$fixtureRoot = __DIR__ . '/Fixtures/bootstrap';

if (!defined('SRC_PATH')) {
    define('SRC_PATH', str_replace('\\', '/', realpath(__DIR__ . '/../src') ?: (__DIR__ . '/../src')));
}

if (!defined('DOCUMENT_PATH')) {
    define('DOCUMENT_PATH', str_replace('\\', '/', $fixtureRoot . '/document'));
}

if (!defined('SETTINGS_PATH')) {
    define('SETTINGS_PATH', str_replace('\\', '/', $fixtureRoot . '/settings'));
}

if (!class_exists('Bootstrap', false)) {
    class Bootstrap
    {
        public static string $contentToInclude = '';
    }
}
