<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\PHPX\PHPX;
use PP\PrismaPHPSettings;
use PP\PrismaSettings;

final class PHPXTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setStaticProperty(PHPX::class, 'publicPropertyTypeCache', []);
        $this->setStaticProperty(PHPX::class, 'publicPropertyTypeInfoCache', []);
        $this->setStaticProperty(PHPX::class, 'publicPropertyLookupCache', []);
        $this->setStaticProperty(PHPX::class, 'mergedClassCache', []);
    }

    public function testConstructorCoercesPublicPropsAndReusesCachedMetadata(): void
    {
        $first = new PHPXFixture([
            'count' => '7',
            'label' => 'alpha',
            'ignored' => 'discarded',
        ]);
        $second = new PHPXFixture(['count' => '9']);

        $propertyCache = $this->getStaticProperty(PHPX::class, 'publicPropertyTypeCache');

        self::assertSame(7, $first->count);
        self::assertSame('alpha', $first->label);
        self::assertSame(9, $second->count);
        self::assertCount(1, $propertyCache);
        self::assertArrayHasKey(PHPXFixture::class, $propertyCache);
        self::assertSame(['count', 'label'], array_keys($propertyCache[PHPXFixture::class]));
    }

    public function testGetMergeClassesCachesRepeatedResults(): void
    {
        PrismaPHPSettings::$option = new PrismaSettings([
            'tailwindcss' => false,
        ]);

        $component = new PHPXMergeFixture();
        $first = $component->mergeClassesForTest('px-2 py-1 rounded', 'px-4 rounded bg-blue-500');
        $second = $component->mergeClassesForTest('px-2 py-1 rounded', 'px-4 rounded bg-blue-500');

        $cache = $this->getStaticProperty(PHPX::class, 'mergedClassCache');

        self::assertSame($first, $second);
        self::assertCount(1, $cache);
    }

    public function testConstructorTreatsValuelessBooleanAliasesAsPresentWithoutPassingThemThrough(): void
    {
        $component = new PHPXBooleanAliasFixture([
            'as-child' => '',
            'data-slot' => 'button',
        ]);

        $attributes = $component->attributesForTest();
        $serializableProps = $component->filterIncomingPropsForRootSerialization([
            'as-child' => '',
            'data-slot' => 'button',
        ]);

        self::assertTrue($component->asChild);
        self::assertStringContainsString("data-slot='button'", $attributes);
        self::assertStringNotContainsString('asChild', $attributes);
        self::assertStringNotContainsString('as-child', $attributes);
        self::assertStringNotContainsString('aschild', $attributes);
        self::assertSame(['data-slot' => 'button'], $serializableProps);
    }

    public function testConstructorMapsCustomKebabCasePropsIntoCamelCaseClassProperties(): void
    {
        $component = new PHPXCustomAliasFixture([
            'as-child' => '',
            'data-state' => 'open',
            'on-change-checked' => '{toggleHome}',
            'aria-label' => 'Home',
        ]);

        $attributes = $component->attributesForTest();
        $serializableProps = $component->filterIncomingPropsForRootSerialization([
            'as-child' => '',
            'data-state' => 'open',
            'on-change-checked' => '{toggleHome}',
            'aria-label' => 'Home',
        ]);

        self::assertTrue($component->asChild);
        self::assertSame('open', $component->dataState);
        self::assertSame('{toggleHome}', $component->onChangeChecked);
        self::assertStringContainsString("aria-label='Home'", $attributes);
        self::assertStringNotContainsString('as-child', $attributes);
        self::assertStringNotContainsString('data-state', $attributes);
        self::assertStringNotContainsString('on-change-checked', $attributes);
        self::assertSame([
            'on-change-checked' => '{toggleHome}',
            'aria-label' => 'Home',
        ], $serializableProps);
    }

    private function getStaticProperty(string $className, string $propertyName): mixed
    {
        $reflection = new \ReflectionClass($className);
        $property = $reflection->getProperty($propertyName);

        return $property->getValue();
    }

    private function setStaticProperty(string $className, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($className);
        $property = $reflection->getProperty($propertyName);
        $property->setValue($value);
    }
}

final class PHPXFixture extends PHPX
{
    public int $count = 0;
    public ?string $label = null;
}

final class PHPXMergeFixture extends PHPX
{
    public function mergeClassesForTest(string|array ...$classes): string
    {
        return $this->getMergeClasses(...$classes);
    }
}

final class PHPXBooleanAliasFixture extends PHPX
{
    public ?bool $asChild = false;

    public function attributesForTest(): string
    {
        return $this->getAttributes();
    }
}

final class PHPXCustomAliasFixture extends PHPX
{
    public ?bool $asChild = false;
    public ?string $dataState = null;
    public ?string $onChangeChecked = null;

    public function attributesForTest(): string
    {
        return $this->getAttributes();
    }
}
