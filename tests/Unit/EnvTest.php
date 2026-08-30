<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\Env;

final class EnvTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $keysToClear = [];

    protected function tearDown(): void
    {
        foreach ($this->keysToClear as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $this->keysToClear = [];
    }

    public function testGetPrefersProcessEnvironmentValues(): void
    {
        $key = $this->trackKey('PP_TEST_ENV_PRIORITY');

        putenv($key . '=from-getenv');
        $_ENV[$key] = 'from-env';
        $_SERVER[$key] = 'from-server';

        self::assertSame('from-getenv', Env::get($key));
    }

    public function testGetFallsBackToSuperglobalsAndNormalizesBooleans(): void
    {
        $key = $this->trackKey('PP_TEST_ENV_BOOL');

        $_ENV[$key] = true;

        self::assertSame('true', Env::get($key));
    }

    public function testStringBoolAndIntUseDefaultsWhenValuesAreMissingOrInvalid(): void
    {
        $missingKey = $this->trackKey('PP_TEST_ENV_MISSING');
        $invalidBoolKey = $this->trackKey('PP_TEST_ENV_INVALID_BOOL');
        $invalidIntKey = $this->trackKey('PP_TEST_ENV_INVALID_INT');

        $_SERVER[$invalidBoolKey] = 'not-a-bool';
        $_ENV[$invalidIntKey] = 'not-a-number';

        self::assertSame('fallback', Env::string($missingKey, 'fallback'));
        self::assertTrue(Env::bool($missingKey, true));
        self::assertFalse(Env::bool($invalidBoolKey, false));
        self::assertSame(42, Env::int($missingKey, 42));
        self::assertSame(42, Env::int($invalidIntKey, 42));
    }

    public function testBoolAndIntParseValidValues(): void
    {
        $boolKey = $this->trackKey('PP_TEST_ENV_BOOL_VALID');
        $intKey = $this->trackKey('PP_TEST_ENV_INT_VALID');

        $_ENV[$boolKey] = 'yes';
        $_SERVER[$intKey] = '123';

        self::assertTrue(Env::bool($boolKey));
        self::assertSame(123, Env::int($intKey));
    }

    private function trackKey(string $key): string
    {
        $this->keysToClear[] = $key;

        return $key;
    }
}