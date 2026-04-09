<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\PrismaPHPSettings;
use PP\Request;

final class RequestTest extends TestCase
{
    private array $serverBackup = [];
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->sessionBackup = $_SESSION ?? [];

        $_SESSION = [];
        PrismaPHPSettings::$localStoreKey = 'pp_test_local_store';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = $this->sessionBackup;
    }

    public function testInitUsesNormalizedServerHeadersForWireAndAuthDetection(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'example.test',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_PP_WIRE_REQUEST' => 'true',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_PP_X_FILE_REQUEST' => 'true',
            'HTTP_AUTHORIZATION' => 'Bearer token-123',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        Request::init();

        self::assertTrue(Request::$isWire);
        self::assertTrue(Request::$isXFileRequest);
        self::assertSame('token-123', Request::getBearerToken());
    }
}