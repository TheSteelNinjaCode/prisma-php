<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\Request;
use PP\StateManager;

final class StateManagerTest extends TestCase
{
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];

        $this->setStaticProperty(StateManager::class, 'state', []);
        $this->setStaticProperty(StateManager::class, 'listeners', []);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;

        $this->setStaticProperty(StateManager::class, 'state', []);
        $this->setStaticProperty(StateManager::class, 'listeners', []);
    }

    public function testInitClearsNonWireStateWithoutLeavingSessionPayloadBehind(): void
    {
        Request::$isWire = false;
        $_SESSION['app_state_F989A'] = json_encode(['count' => 1], JSON_THROW_ON_ERROR);
        $this->setStaticProperty(StateManager::class, 'state', ['stale' => true]);

        StateManager::init();

        self::assertSame([], StateManager::getState()->getArrayCopy());
        self::assertArrayNotHasKey('app_state_F989A', $_SESSION);
    }

    public function testInitLoadsWireStateFromSession(): void
    {
        Request::$isWire = true;
        $_SESSION['app_state_F989A'] = json_encode(['count' => 3], JSON_THROW_ON_ERROR);

        StateManager::init();

        self::assertSame(3, StateManager::getState('count'));
    }

    public function testInitClearsStaleStaticStateWhenWireSessionIsMissing(): void
    {
        Request::$isWire = true;
        $this->setStaticProperty(StateManager::class, 'state', ['stale' => true]);

        StateManager::init();

        self::assertSame([], StateManager::getState()->getArrayCopy());
        self::assertNull(StateManager::getState('stale'));
    }

    private function setStaticProperty(string $className, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($className);
        $property = $reflection->getProperty($propertyName);
        $property->setValue($value);
    }
}