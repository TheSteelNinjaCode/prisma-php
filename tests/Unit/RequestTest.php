<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
        $this->setStaticProperty(Request::class, 'rawInput', '');
        $this->setStaticProperty(Request::class, 'rawInputLoaded', false);
        $this->setStaticProperty(Request::class, 'contentTypeInfoSource', null);
        $this->setStaticProperty(Request::class, 'contentTypeInfo', [
            'normalized' => '',
            'isJson' => false,
            'isForm' => false,
            'isMultipart' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = $this->sessionBackup;

        $this->setStaticProperty(Request::class, 'rawInput', '');
        $this->setStaticProperty(Request::class, 'rawInputLoaded', false);
        $this->setStaticProperty(Request::class, 'contentTypeInfoSource', null);
        $this->setStaticProperty(Request::class, 'contentTypeInfo', [
            'normalized' => '',
            'isJson' => false,
            'isForm' => false,
            'isMultipart' => false,
        ]);
    }

    public function testInitUsesNormalizedServerHeadersForWireAndAuthDetection(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'example.test',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_X_PULSEPOINT_WIRE' => 'true',
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

    public function testInitDetectsPulsePointRpcRequest(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'example.test',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_X_PULSEPOINT_WIRE' => 'true',
            'HTTP_X_PP_RPC' => 'true',
            'HTTP_X_PP_FUNCTION' => 'saveProfile',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        Request::init();

        self::assertTrue(Request::$isWire);
        self::assertTrue(Request::$isRpc);
        self::assertFalse(Request::$isNavigation);
    }

    public function testInitDetectsPulsePointNavigationRequest(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.test',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_X_PULSEPOINT_WIRE' => 'true',
            'HTTP_X_PP_NAVIGATION' => 'true',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        Request::init();

        self::assertTrue(Request::$isWire);
        self::assertFalse(Request::$isRpc);
        self::assertTrue(Request::$isNavigation);
    }

    public function testRpcDetectionRequiresPostMethod(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.test',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_X_PP_RPC' => 'true',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        Request::init();

        self::assertFalse(Request::$isRpc);
    }

    public function testGetParamsUsesCachedRawInputForJsonBodies(): void
    {
        Request::$method = 'POST';
        Request::$contentType = 'application/json';
        $this->setStaticProperty(Request::class, 'rawInput', '{"name":"Ada"}');
        $this->setStaticProperty(Request::class, 'rawInputLoaded', true);

        $params = $this->invokePrivateStaticMethod(Request::class, 'getParams');

        self::assertSame('Ada', $params['name']);
        self::assertSame(['name' => 'Ada'], Request::$data);
    }

    public function testGetParamsUsesCachedRawInputForFormBodies(): void
    {
        Request::$method = 'POST';
        Request::$contentType = 'application/x-www-form-urlencoded';
        $this->setStaticProperty(Request::class, 'rawInput', 'name=Ada&role=admin');
        $this->setStaticProperty(Request::class, 'rawInputLoaded', true);

        $params = $this->invokePrivateStaticMethod(Request::class, 'getParams');

        self::assertSame('Ada', $params['name']);
        self::assertSame('admin', $params['role']);
    }

    private function invokePrivateStaticMethod(string $className, string $methodName): mixed
    {
        $reflection = new \ReflectionMethod($className, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invoke(null);
    }

    private function setStaticProperty(string $className, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($className);
        $property = $reflection->getProperty($propertyName);
        $property->setValue($value);
    }
}