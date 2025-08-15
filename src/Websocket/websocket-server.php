<?php

declare(strict_types=1);

namespace PPHP\Websocket;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require_once $root . '/settings/paths.php';

use Dotenv\Dotenv;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use PPHP\Websocket\ConnectionManager;
use Throwable;

// ── Load .env (optional) and timezone ─────────────────────────────────────────
if (file_exists(DOCUMENT_PATH . '/.env')) {
    Dotenv::createImmutable(DOCUMENT_PATH)->safeLoad();
}
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// ── Tiny argv parser: allows --host=0.0.0.0 --port=8080 ──────────────────────
$cli = [];
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
        $cli[$m[1]] = $m[2];
    }
}

// ── Resolve settings (env → cli defaults) ────────────────────────────────────
$appName = $_ENV['WS_NAME']       ?? 'prisma-php-ws';
$appVer  = $_ENV['WS_VERSION']    ?? '0.0.1';
$host    = $cli['host']           ?? ($_ENV['WS_HOST'] ?? '127.0.0.1');
$port    = (int)($cli['port']     ?? ($_ENV['WS_PORT'] ?? 8080));
$verbose = filter_var($cli['verbose'] ?? ($_ENV['WS_VERBOSE'] ?? 'true'), FILTER_VALIDATE_BOOLEAN);

// ── Console helpers ──────────────────────────────────────────────────────────
$color = static fn(string $t, string $c) => "\033[{$c}m{$t}\033[0m";
$info  = static fn(string $t) => $color($t, '1;36');
$ok    = static fn(string $t) => $color($t, '32');
$warn  = static fn(string $t) => $color($t, '33');
$err   = static fn(string $t) => $color($t, '31');

// ── Preflight: check if port is free (nice error if not) ─────────────────────
$probe = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if ($probe === false) {
    fwrite(STDERR, $err("✖ Port {$port} on {$host} is not available: {$errstr}\n"));
    exit(1);
}
fclose($probe);

// ── Build app ────────────────────────────────────────────────────────────────
$manager = new ConnectionManager(); // your app component
$server  = IoServer::factory(
    new HttpServer(new WsServer($manager)),
    $port,
    $host
);

$pid  = getmypid() ?: 0;
$url  = "ws://{$host}:{$port}";
$ts   = date('Y-m-d H:i:s');

echo PHP_EOL;
echo $info("⚡  {$appName} (v{$appVer})") . PHP_EOL;
echo $warn("→ WebSocket server starting…") . PHP_EOL;
echo "   Host:       {$host}" . PHP_EOL;
echo "   Port:       {$port}" . PHP_EOL;
echo "   URL:        {$url}" . PHP_EOL;
echo "   PID:        {$pid}" . PHP_EOL;
echo "   Started:    {$ts}" . PHP_EOL;

// ── Graceful shutdown & periodic logs (if loop available) ────────────────────
$loop = property_exists($server, 'loop') ? $server->loop : null;
if ($loop instanceof \React\EventLoop\LoopInterface) {
    // Periodic stats every 60s
    $loop->addPeriodicTimer(60, function () use ($ok) {
        $mem = function_exists('memory_get_usage') ? number_format(memory_get_usage(true) / 1048576, 2) . ' MB' : 'n/a';
        $msg = "✓ Heartbeat — memory: {$mem}";
        echo $ok($msg) . PHP_EOL;
    });

    // Signal handlers (needs pcntl)
    if (function_exists('pcntl_signal') && method_exists($loop, 'addSignal')) {
        $stop = function (int $sig) use ($warn, $loop) {
            echo PHP_EOL . $warn("⏹  Caught signal {$sig}. Shutting down…") . PHP_EOL;
            $loop->stop();
        };
        $loop->addSignal(SIGINT, $stop);
        $loop->addSignal(SIGTERM, $stop);
    }
}

// ── Run ──────────────────────────────────────────────────────────────────────
try {
    echo $ok("✓ Listening on {$url}") . PHP_EOL;
    if ($verbose) {
        // Basic error/exception logging
        set_error_handler(function ($severity, $message, $file, $line) use ($err) {
            // Respect @-silence
            if (!(error_reporting() & $severity)) return;
            fwrite(STDERR, $err("PHP Error [{$severity}] {$message} @ {$file}:{$line}\n"));
        });
        set_exception_handler(function (Throwable $e) use ($err) {
            fwrite(STDERR, $err('Uncaught Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"));
        });
    }

    $server->run(); // blocks until loop->stop()

    echo PHP_EOL . $ok('✔ Server stopped.') . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $err('✖ Server error: ' . $e->getMessage()) . PHP_EOL);
    exit(1);
}
