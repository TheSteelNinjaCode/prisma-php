<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\PHPX\PHPX;

final class PHPXTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setStaticProperty(PHPX::class, 'publicPropertyTypeCache', []);
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