<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Lib\Websocket\ConnectionManager;

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ConnectionManager()
        )
    ),
    8080
);

$server->run();
