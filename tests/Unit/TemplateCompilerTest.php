<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\PHPX\TemplateCompiler;
use PP\PrismaPHPSettings;

final class TemplateCompilerTest extends TestCase
{
    protected function setUp(): void
    {
        PrismaPHPSettings::$classLogFiles = [];

        $this->setStaticProperty(TemplateCompiler::class, 'compiledCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheStats', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheEnabled', true);
        $this->setStaticProperty(TemplateCompiler::class, 'maxCacheSize', 2);
    }

    protected function tearDown(): void
    {
        $this->setStaticProperty(TemplateCompiler::class, 'compiledCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheStats', []);
        $this->setStaticProperty(TemplateCompiler::class, 'maxCacheSize', 100);
    }

    public function testCompileCacheEvictsLeastUsedEntryAndStaysBounded(): void
    {
        $firstTemplate = '<div>first</div>';
        $secondTemplate = '<div>second</div>';
        $thirdTemplate = '<div>third</div>';

        self::assertSame($firstTemplate, TemplateCompiler::compile($firstTemplate));
        self::assertSame($secondTemplate, TemplateCompiler::compile($secondTemplate));
        self::assertSame($firstTemplate, TemplateCompiler::compile($firstTemplate));
        self::assertSame($thirdTemplate, TemplateCompiler::compile($thirdTemplate));

        $compiledCache = $this->getStaticProperty(TemplateCompiler::class, 'compiledCache');
        $cacheStats = $this->getStaticProperty(TemplateCompiler::class, 'cacheStats');

        self::assertCount(2, $compiledCache);
        self::assertCount(2, $cacheStats);
        self::assertArrayHasKey(md5($firstTemplate), $compiledCache);
        self::assertArrayHasKey(md5($thirdTemplate), $compiledCache);
        self::assertArrayNotHasKey(md5($secondTemplate), $compiledCache);
    }

    public function testClassReflectionCachesRequiredPropsAndChildrenSupport(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'getClassReflection');
        $method->setAccessible(true);

        $reflection = $method->invoke(null, TemplateCompilerFixture::class);

        self::assertSame(['title', 'children'], $reflection['propertyNames']);
        self::assertSame(['title'], $reflection['requiredProps']);
        self::assertTrue($reflection['hasPublicChildren']);
        self::assertArrayHasKey('title', $reflection['allowedProps']);
        self::assertArrayHasKey('children', $reflection['allowedProps']);
    }

    public function testCompileComponentHtmlNormalizesDynamicAttributesAndInjectsProps(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
        $method->setAccessible(true);

        $html = '<button dataFoo="{bar}"></button>';
        $output = $method->invoke(null, $html, 's1', ['dataBar' => '{baz}', 'title' => 'Save']);

        self::assertStringContainsString('pp-component="s1"', $output);
        self::assertStringContainsString('data-foo="{bar}"', $output);
        self::assertStringContainsString('data-bar="{baz}"', $output);
        self::assertStringContainsString('title="Save"', $output);
    }

    public function testCompileComponentHtmlDoesNotDuplicateDescendantAttributesOnRoot(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
        $method->setAccessible(true);

        $html = '<div><span title="child"></span></div>';
        $output = $method->invoke(null, $html, 's1', ['title' => 'Root']);

        self::assertSame(1, substr_count($output, 'title="'));
        self::assertStringContainsString('<span title="child"></span>', $output);
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

final class TemplateCompilerFixture
{
    public string $title;
    public ?string $children = null;
    private string $ignored = 'nope';
}