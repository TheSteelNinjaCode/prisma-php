<?php

declare(strict_types=1);

namespace Lib;

use Lib\Request;

class StateManager
{
    private const APP_STATE = 'app_state_F989A';
    private static array $state = [];
    private static array $listeners = [];

    public static function init(): void
    {
        self::loadState();

        if (!Request::$isWire) {
            self::resetState();
        }
    }

    /**
     * Gets the state value for the specified key.
     *
     * @param string|null $key The key of the state value to get.
     * @param mixed $initialValue The initial value to set if the key does not exist.
     * @return mixed The state value for the specified key.
     */
    public static function getState(?string $key = null, mixed $initialValue = null): mixed
    {
        if ($key === null) {
            return new \ArrayObject(self::$state, \ArrayObject::ARRAY_AS_PROPS);
        }

        $value = self::$state[$key] ?? $initialValue;

        return is_array($value) ? new \ArrayObject($value, \ArrayObject::ARRAY_AS_PROPS) : $value;
    }

    /**
     * Sets the state value for the specified key.
     *
     * @param string $key The key of the state value to set.
     * @param mixed $value The value to set.
     */
    public static function setState(string $key, mixed $value = null): void
    {
        if (array_key_exists($key, $GLOBALS)) {
            $GLOBALS[$key] = $value;
        }

        self::$state[$key] = $value;

        self::notifyListeners();
        self::saveState();
    }

    /**
     * Subscribes a listener to state changes.
     *
     * @param callable $listener The listener function to subscribe.
     * @return callable A function that can be called to unsubscribe the listener.
     */
    public static function subscribe(callable $listener): callable
    {
        self::$listeners[] = $listener;
        $listener(self::$state);
        return fn() => self::$listeners = array_filter(self::$listeners, fn($l) => $l !== $listener);
    }

    /**
     * Saves the current state to storage.
     */
    private static function saveState(): void
    {
        $_SESSION[self::APP_STATE] = json_encode(self::$state, JSON_THROW_ON_ERROR);
    }

    /**
     * Loads the state from storage, if available.
     */
    public static function loadState(): void
    {
        if (isset($_SESSION[self::APP_STATE])) {
            $loadedState = json_decode($_SESSION[self::APP_STATE], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($loadedState)) {
                self::$state = $loadedState;
                self::notifyListeners();
            }
        }
    }

    /**
     * Resets the application state to an empty array.
     *
     * @param string|null $key The key of the state value to reset.
     */
    public static function resetState(?string $key = null): void
    {
        if ($key !== null) {
            if (array_key_exists($key, self::$state)) {
                self::$state[$key] = null;

                if (array_key_exists($key, $GLOBALS)) {
                    $GLOBALS[$key] = null;
                }
            }
        } else {
            self::$state = [];
        }

        self::notifyListeners();
        self::saveState();
    }

    /**
     * Notifies all listeners of state changes.
     */
    private static function notifyListeners(): void
    {
        foreach (self::$listeners as $listener) {
            $listener(self::$state);
        }
    }
}
