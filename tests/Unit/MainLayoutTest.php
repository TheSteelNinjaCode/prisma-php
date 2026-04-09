<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\MainLayout;
use PP\Set;

final class MainLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        $this->setStaticProperty(MainLayout::class, 'headScripts', new Set());
        $this->setStaticProperty(MainLayout::class, 'footerScripts', []);
        $this->setStaticProperty(MainLayout::class, 'processedScripts', []);
        $this->setStaticProperty(MainLayout::class, 'footerComponentCounter', 0);
    }

    protected function tearDown(): void
    {
        $this->setStaticProperty(MainLayout::class, 'headScripts', new Set());
        $this->setStaticProperty(MainLayout::class, 'footerScripts', []);
        $this->setStaticProperty(MainLayout::class, 'processedScripts', []);
        $this->setStaticProperty(MainLayout::class, 'footerComponentCounter', 0);
    }

    public function testAddFooterScriptDeduplicatesAndPreparesAttributes(): void
    {
        $script = '<script dataFoo="{bar}">console.log(1)</script>';

        $this->registerFooterScript($script, $script);

        $output = MainLayout::outputFooterScripts();

        self::assertSame(1, substr_count($output, '<script'));
        self::assertStringContainsString('pp-component="', $output);
        self::assertStringContainsString('type="text/pp"', $output);
        self::assertStringContainsString('data-foo="{bar}"', $output);
    }

    public function testAddHeadScriptPreparesDynamicAttributesOnce(): void
    {
        MainLayout::addHeadScript(
            '<script src="/app.js"></script>',
            '<link rel="stylesheet" href="/app.css">',
            '<style>.demo{color:red;}</style>'
        );

        $output = MainLayout::outputHeadScripts();

        self::assertStringContainsString('pp-dynamic-script="81D7D"', $output);
        self::assertStringContainsString('pp-dynamic-link="81D7D"', $output);
        self::assertStringContainsString('pp-dynamic-style="81D7D"', $output);
    }

    public function testClearFooterScriptsResetsStoredScripts(): void
    {
        $this->registerFooterScript('<script>console.log(1)</script>');

        MainLayout::clearFooterScripts();

        self::assertSame('', MainLayout::outputFooterScripts());
        self::assertSame([], $this->getStaticProperty(MainLayout::class, 'footerScripts'));
    }

    public function testClearHeadScriptsResetsStoredScripts(): void
    {
        MainLayout::addHeadScript('<script src="/app.js"></script>');

        MainLayout::clearHeadScripts();

        self::assertSame('', MainLayout::outputHeadScripts());
        self::assertSame([], $this->getStaticProperty(MainLayout::class, 'headScripts')->values());
    }

    private function registerFooterScript(string ...$scripts): void
    {
        MainLayout::addFooterScript(...$scripts);
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