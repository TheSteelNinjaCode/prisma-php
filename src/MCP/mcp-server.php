<?php

declare(strict_types=1);

namespace PPHP\MCP;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require $root . '/settings/paths.php';

use Dotenv\Dotenv;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use Throwable;

// ── Load .env (optional) and timezone ──────────────────────────────────────────
if (file_exists(DOCUMENT_PATH . '/.env')) {
    Dotenv::createImmutable(DOCUMENT_PATH)->safeLoad();
}
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// ── Resolve settings (with sane defaults) ─────────────────────────────────────
$appName    = $_ENV['MCP_NAME']         ?? 'prisma-php-mcp';
$appVersion = $_ENV['MCP_VERSION']      ?? '0.0.1';
$host       = $_ENV['MCP_HOST']         ?? '127.0.0.1';
$port       = (int)($_ENV['MCP_PORT']   ?? 4000);
$prefix     = trim($_ENV['MCP_PATH_PREFIX'] ?? 'mcp', '/');
$enableJson = filter_var($_ENV['MCP_JSON_RESPONSE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

// ── Build server and discover tools ───────────────────────────────────────────
$server = Server::make()
    ->withServerInfo($appName, $appVersion)
    ->build();

// Scan your source tree for #[McpTool] classes
$server->discover(DOCUMENT_PATH, ['src']);

// ── Pretty console output ─────────────────────────────────────────────────────
$pid    = getmypid() ?: 0;
$base   = "http://{$host}:{$port}/{$prefix}";
$color  = static fn(string $t, string $c) => "\033[{$c}m{$t}\033[0m";

echo PHP_EOL;
echo $color("⚙  {$appName} (v{$appVersion})", '1;36') . PHP_EOL;
echo $color("→ MCP server starting…", '33') . PHP_EOL;
echo "   Host:       {$host}" . PHP_EOL;
echo "   Port:       {$port}" . PHP_EOL;
echo "   Path:       /{$prefix}" . PHP_EOL;
echo "   JSON resp:  " . ($enableJson ? 'enabled' : 'disabled') . PHP_EOL;
echo "   PID:        {$pid}" . PHP_EOL;
echo "   URL:        {$base}" . PHP_EOL;

// ── Graceful shutdown (if pcntl is available) ─────────────────────────────────
if (function_exists('pcntl_signal')) {
    $stop = function (int $sig) use ($color) {
        echo PHP_EOL . $color("⏹  Caught signal {$sig}. Shutting down…", '33') . PHP_EOL;
        exit(0);
    };
    pcntl_signal(SIGINT,  $stop);
    pcntl_signal(SIGTERM, $stop);
}

// ── Listen ────────────────────────────────────────────────────────────────────
try {
    $transport = new StreamableHttpServerTransport(
        $host,
        $port,
        $prefix,
        null,            // sslContext
        true,            // logger
        $enableJson      // enableJsonResponse
        // , false        // (optional) stateless
    );
    echo $color("✓ Listening on {$base}", '32') . PHP_EOL;
    $server->listen($transport);
    echo PHP_EOL . $color('✔ Server stopped.', '32') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $color('✖ Server error: ', '31') . $e->getMessage() . PHP_EOL);
    exit(1);
}
