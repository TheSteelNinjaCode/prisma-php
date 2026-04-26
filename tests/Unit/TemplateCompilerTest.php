<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\MainLayout;
use PP\PHPX\TemplateCompiler;
use PP\PrismaPHPSettings;
use PP\Set;

final class TemplateCompilerTest extends TestCase
{
    protected function setUp(): void
    {
        PrismaPHPSettings::$classLogFiles = [];

        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);
        $this->setStaticProperty(TemplateCompiler::class, 'compiledCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheStats', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheEnabled', true);
        $this->setStaticProperty(TemplateCompiler::class, 'maxCacheSize', 2);
        $this->setStaticProperty(TemplateCompiler::class, 'componentPropMetadataCache', []);
    }

    protected function tearDown(): void
    {
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);
        $this->setStaticProperty(TemplateCompiler::class, 'compiledCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheStats', []);
        $this->setStaticProperty(TemplateCompiler::class, 'maxCacheSize', 100);
        $this->setStaticProperty(TemplateCompiler::class, 'componentPropMetadataCache', []);
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

    public function testCompileComponentHtmlInjectsRootEventListenersOnSimpleMarkup(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
        $method->setAccessible(true);

        $html = '<button>Save</button>';
        $output = $method->invoke(null, $html, 's1', ['onClick' => '{save}', 'title' => 'Save']);

        self::assertStringContainsString('pp-component="s1"', $output);
        self::assertStringContainsString('on-click="{save}"', $output);
        self::assertStringContainsString('title="Save"', $output);
        self::assertStringContainsString('>Save</button>', $output);
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

    public function testCompileComponentHtmlWrapsMatchingEventElementsOutsideExistingOwnerTemplates(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
        $method->setAccessible(true);

        $html = '<div><template pp-owner="child"><button onClick="{save}"></button></template><button onClick="{save}"></button><button onClick="{skip}"></button></div>';
        $output = $method->invoke(null, $html, 's1', ['onClick' => '{save}'], 'parent');

        self::assertSame(1, substr_count($output, 'pp-owner="parent"'));
        self::assertSame(1, substr_count($output, 'pp-owner="child"'));
        self::assertStringStartsWith('<div pp-component="s1">', $output);
        self::assertStringContainsString('<template pp-owner="parent"><button on-click="{save}"></button></template>', $output);
        self::assertStringContainsString('<button on-click="{skip}"></button>', $output);
    }

    public function testCompileComponentHtmlStillFindsDescendantAttributesInsideWrappedEventSubtree(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
        $method->setAccessible(true);

        $html = '<div><button onClick="{save}"><span title="child"></span></button></div>';
        $output = $method->invoke(null, $html, 's1', ['title' => 'Root', 'onClick' => '{save}'], 'parent');

        self::assertSame(1, substr_count($output, 'title="'));
        self::assertStringStartsWith('<div pp-component="s1">', $output);
        self::assertStringNotContainsString('<div pp-component="s1" title="Root">', $output);
        self::assertStringContainsString('<span title="child"></span>', $output);
        self::assertStringContainsString('<template pp-owner="parent"><button on-click="{save}">', $output);
    }

    public function testCompileComponentHtmlSupportsHtmlBooleanAttributesInNestedMarkup(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
        $method->setAccessible(true);

        $html = '<div><button disabled>Save</button></div>';
        $output = $method->invoke(null, $html, 's1');

        self::assertStringStartsWith('<div pp-component="s1">', $output);
        self::assertMatchesRegularExpression('/<button[^>]*disabled(?:="(?:disabled)?")?[^>]*>Save<\/button>/', $output);
    }

    public function testCompileResolvesHtmlFirstComponentTags(): void
    {
        PrismaPHPSettings::$classLogFiles = [
            'x-button' => [[
                'className' => HtmlFirstButtonFixture::class,
                'filePath' => __FILE__,
            ]],
        ];
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);

        $output = TemplateCompiler::compile('<div><x-button>Click Me</x-button></div>');

        self::assertStringContainsString('<button', $output);
        self::assertStringContainsString('Click Me', $output);
        self::assertStringContainsString('pp-component="s', $output);
        self::assertStringNotContainsString('<x-button', $output);
    }

    public function testCompileTreatsValuelessKebabBooleanPropsAsTrueWithoutLeakingAliases(): void
    {
        PrismaPHPSettings::$classLogFiles = [
            'x-button' => [[
                'className' => HtmlFirstAsChildButtonFixture::class,
                'filePath' => __FILE__,
            ]],
        ];
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);

        $output = TemplateCompiler::compile('<div><x-button as-child>Click Me</x-button></div>');

        self::assertStringContainsString('<a', $output);
        self::assertStringContainsString('href="/"', $output);
        self::assertStringContainsString('>Click Me</a>', $output);
        self::assertStringNotContainsString('<button', $output);
        self::assertStringNotContainsString('as-child', $output);
        self::assertStringNotContainsString('aschild', $output);
    }

    public function testCompileRejectsUnknownHtmlFirstComponentTags(): void
    {
        PrismaPHPSettings::$classLogFiles = [];
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Component 'x-button' not found.");

        TemplateCompiler::compile('<div><x-button>Click Me</x-button></div>');
    }

    public function testCompileKeepsPulsePointScriptsRawWithoutCdataWrapper(): void
    {
        $html = '<div><script type="text/pp">const ready = value > 0 && enabled;</script></div>';
        $output = TemplateCompiler::compile($html);

        self::assertStringContainsString('<script type="text/pp">const ready = value > 0 && enabled;</script>', $output);
        self::assertStringNotContainsString('<![CDATA[', $output);
        self::assertStringNotContainsString('&amp;&amp;', $output);
        self::assertStringNotContainsString('&gt;', $output);
    }

    public function testCompileKeepsMustacheComparisonOperatorsRawInHtmlText(): void
    {
        $html = '<div>{count < 2 ? "small" : "big"}</div>';
        $output = TemplateCompiler::compile($html);

        self::assertSame($html, $output);
        self::assertStringNotContainsString('&lt;', $output);
        self::assertStringNotContainsString('&gt;', $output);
    }

    public function testCompileTurnsBraceEntitiesIntoLiteralCodeText(): void
    {
        $html = '<div><pre><code>&lt;Calendar selected=&#123;selectedDate&#125; /&gt;</code></pre></div>';
        $output = TemplateCompiler::compile($html);

        self::assertStringContainsString('<code>&lt;Calendar selected={selectedDate} /&gt;</code>', $output);
        self::assertStringNotContainsString('&amp;#123;', $output);
        self::assertStringNotContainsString('&amp;#125;', $output);
    }

    public function testInjectDynamicContentPlacesMetadataHeadScriptsAndFooterScripts(): void
    {
        $this->setStaticProperty(MainLayout::class, 'headScripts', new Set());
        $this->setStaticProperty(MainLayout::class, 'footerScripts', []);
        $this->setStaticProperty(MainLayout::class, 'processedScripts', []);
        $this->setStaticProperty(MainLayout::class, 'customMetadata', []);
        $this->setStaticProperty(MainLayout::class, 'headScriptsOutputCache', null);
        $this->setStaticProperty(MainLayout::class, 'footerScriptsOutputCache', null);

        MainLayout::$title = 'Dashboard';
        MainLayout::$description = 'Overview';
        MainLayout::addHeadScript('<script src="/app.js"></script>');
        MainLayout::addFooterScript('<script>console.log(1)</script>');

        $html = '<html><head data-test="1"></head><body><main>Hello</main></body></html>';
        $output = TemplateCompiler::injectDynamicContent($html);

        self::assertStringContainsString('<head data-test="1"><meta charset="UTF-8">', $output);
        self::assertStringContainsString('pp-dynamic-script="81D7D"', $output);
        self::assertStringContainsString('pp-component="', $output);
        self::assertStringContainsString('type="text/pp"', $output);
        self::assertMatchesRegularExpression('/<script[^>]*pp-dynamic-script="81D7D"[^>]*><\/script><\/head>/i', $output);
        self::assertMatchesRegularExpression('/type="text\/pp">console\.log\(1\)<\/script><\/body>/i', $output);
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

final class HtmlFirstButtonFixture extends \PP\PHPX\PHPX
{
    public mixed $children = null;

    public function render(): string
    {
        return '<button>' . ($this->children ?? '') . '</button>';
    }
}

final class HtmlFirstAsChildButtonFixture extends \PP\PHPX\PHPX
{
    public ?bool $asChild = false;
    public mixed $children = null;

    public function render(): string
    {
        if ($this->asChild) {
            return '<a href="/">' . ($this->children ?? '') . '</a>';
        }

        return '<button>' . ($this->children ?? '') . '</button>';
    }
}