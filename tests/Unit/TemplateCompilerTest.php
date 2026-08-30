<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use PP\MainLayout;
use PP\PHPX\TemplateCompiler;
use PP\PrismaPHPSettings;
use PP\Set;

final class TemplateCompilerTest extends TestCase
{
    protected function setUp(): void
    {
        PrismaPHPSettings::$classLogFiles = [];
        $this->setStaticProperty(PrismaPHPSettings::class, 'classLogFilesLoaded', true);

        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);
        $this->setStaticProperty(TemplateCompiler::class, 'compiledCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheStats', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheEnabled', true);
        $this->setStaticProperty(TemplateCompiler::class, 'maxCacheSize', 2);
        $this->setStaticProperty(TemplateCompiler::class, 'componentPropMetadataCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'componentImportCache', []);
        \Bootstrap::$contentToInclude = '';
    }

    protected function tearDown(): void
    {
        PrismaPHPSettings::$classLogFiles = [];
        $this->setStaticProperty(PrismaPHPSettings::class, 'classLogFilesLoaded', false);
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);
        $this->setStaticProperty(TemplateCompiler::class, 'compiledCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'cacheStats', []);
        $this->setStaticProperty(TemplateCompiler::class, 'maxCacheSize', 100);
        $this->setStaticProperty(TemplateCompiler::class, 'componentPropMetadataCache', []);
        $this->setStaticProperty(TemplateCompiler::class, 'componentImportCache', []);
        \Bootstrap::$contentToInclude = '';
    }

    public function testCreateHtmlFragmentDomPreservesUtf8EntitiesAcrossRepeatedRoundTrips(): void
    {
        $html = '<div><span aria-hidden="true">&#8599;</span><span aria-hidden="true">&#9733;</span><span aria-hidden="true">&#8594;</span></div>';

        $firstPass = TemplateCompiler::innerHtml(TemplateCompiler::createHtmlFragmentDom($html));
        $secondPass = TemplateCompiler::innerHtml(TemplateCompiler::createHtmlFragmentDom($firstPass));

        self::assertStringContainsString(
            html_entity_decode('&#8599;', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $firstPass
        );
        self::assertStringContainsString(
            html_entity_decode('&#9733;', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $firstPass
        );
        self::assertStringContainsString(
            html_entity_decode('&#8594;', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $firstPass
        );
        self::assertSame($firstPass, $secondPass);
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
        $output = $method->invoke(null, $html, 's1', ['onclick' => '{save}', 'title' => 'Save']);

        self::assertStringContainsString('pp-component="s1"', $output);
        self::assertStringContainsString('onclick="{save}"', $output);
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

        $html = '<div><template pp-owner="child"><button onclick="{save}"></button></template><button onclick="{save}"></button><button onclick="{skip}"></button></div>';
        $output = $method->invoke(null, $html, 's1', ['onclick' => '{save}'], 'parent');

        self::assertSame(1, substr_count($output, 'pp-owner="parent"'));
        self::assertSame(1, substr_count($output, 'pp-owner="child"'));
        self::assertStringStartsWith('<div pp-component="s1">', $output);
        self::assertStringContainsString('<template pp-owner="parent"><button onclick="{save}"></button></template>', $output);
        self::assertStringContainsString('<button onclick="{skip}"></button>', $output);
    }

    public function testCompileComponentHtmlStillFindsDescendantAttributesInsideWrappedEventSubtree(): void
    {
        $method = new \ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
        $method->setAccessible(true);

        $html = '<div><button onclick="{save}"><span title="child"></span></button></div>';
        $output = $method->invoke(null, $html, 's1', ['title' => 'Root', 'onclick' => '{save}'], 'parent');

        self::assertSame(1, substr_count($output, 'title="'));
        self::assertStringStartsWith('<div pp-component="s1">', $output);
        self::assertStringNotContainsString('<div pp-component="s1" title="Root">', $output);
        self::assertStringContainsString('<span title="child"></span>', $output);
        self::assertStringContainsString('<template pp-owner="parent"><button onclick="{save}">', $output);
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
        self::assertStringContainsString('Click Me', $output);
        self::assertStringContainsString('<template pp-owner="app">', $output);
        self::assertStringNotContainsString('<button', $output);
        self::assertStringNotContainsString('as-child', $output);
        self::assertStringNotContainsString('aschild', $output);
    }

    public function testCompileMapsCustomKebabCasePropsIntoHtmlFirstComponentClassProperties(): void
    {
        PrismaPHPSettings::$classLogFiles = [
            'x-button' => [[
                'className' => HtmlFirstCustomAliasFixture::class,
                'filePath' => __FILE__,
            ]],
        ];
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);

        $output = TemplateCompiler::compile(
            '<div><x-button as-child data-state="open" on-change-checked="{toggleHome}"><a href="/">Home</a></x-button></div>'
        );

        self::assertStringContainsString('<section', $output);
        self::assertStringContainsString('data-state="open"', $output);
        self::assertStringContainsString('data-on-change-checked="{toggleHome}"', $output);
        self::assertStringContainsString('<a href="/">Home</a>', $output);
        self::assertSame(1, substr_count($output, 'data-state="open"'));
        self::assertSame(1, substr_count($output, 'data-on-change-checked="{toggleHome}"'));
        self::assertStringNotContainsString('as-child', $output);
        self::assertStringNotContainsString(' on-change-checked="{toggleHome}"', $output);
    }

    public function testCompilePreservesMappedEventAliasesWhenComponentDoesNotRenderThem(): void
    {
        PrismaPHPSettings::$classLogFiles = [
            'x-button' => [[
                'className' => HtmlFirstEventAliasFixture::class,
                'filePath' => __FILE__,
            ]],
        ];
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);

        $output = TemplateCompiler::compile(
            '<div><x-button onclick="{save}" on-open-change="{toggleOpen}">Save</x-button></div>'
        );

        self::assertStringContainsString('<button', $output);
        self::assertStringContainsString('onclick="{save}"', $output);
        self::assertStringContainsString('on-open-change="{toggleOpen}"', $output);
        self::assertStringContainsString('<template pp-owner="app">', $output);
        self::assertStringContainsString('Save', $output);
        self::assertSame(1, substr_count($output, 'onclick="{save}"'));
        self::assertSame(1, substr_count($output, 'on-open-change="{toggleOpen}"'));
    }

    public function testCompileRejectsUnknownHtmlFirstComponentTags(): void
    {
        PrismaPHPSettings::$classLogFiles = [];
        $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Component 'x-button' not found.");

        TemplateCompiler::compile('<div><x-button>Click Me</x-button></div>');
    }

    public function testSelectComponentMappingUsesDirectImportForAmbiguousHtmlFirstTags(): void
    {
        $fixturePath = $this->createComponentImportFixture("<?php\n\nuse Components\\Calendar;\n");

        try {
            \Bootstrap::$contentToInclude = $fixturePath;
            $this->setStaticProperty(TemplateCompiler::class, 'classMappings', [
                'x-calendar' => [
                    [
                        'className' => 'Components\\Calendar',
                        'filePath' => __FILE__,
                    ],
                    [
                        'className' => 'Lib\\PPIcons\\Calendar',
                        'filePath' => __FILE__,
                    ],
                ],
            ]);

            $method = new \ReflectionMethod(TemplateCompiler::class, 'selectComponentMapping');
            $method->setAccessible(true);
            $mapping = $method->invoke(null, 'x-calendar');

            self::assertSame('Components\\Calendar', $mapping['className']);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function testSelectComponentMappingIgnoresAliasedImportsForCanonicalTags(): void
    {
        $fixturePath = $this->createComponentImportFixture("<?php\n\nuse Components\\Calendar as HomeCalendar;\n");

        try {
            \Bootstrap::$contentToInclude = $fixturePath;
            $this->setStaticProperty(TemplateCompiler::class, 'classMappings', [
                'x-calendar' => [
                    [
                        'className' => 'Components\\Calendar',
                        'filePath' => __FILE__,
                    ],
                    [
                        'className' => 'Lib\\PPIcons\\Calendar',
                        'filePath' => __FILE__,
                    ],
                ],
            ]);

            $method = new \ReflectionMethod(TemplateCompiler::class, 'selectComponentMapping');
            $method->setAccessible(true);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Component x-calendar is ambiguous');

            $method->invoke(null, 'x-calendar');
        } finally {
            @unlink($fixturePath);
        }
    }

    public function testSelectComponentMappingRejectsAmbiguousHtmlFirstTagsWithoutExplicitImport(): void
    {
        $fixturePath = $this->createComponentImportFixture("<?php\n\n<div></div>\n");

        try {
            \Bootstrap::$contentToInclude = $fixturePath;
            $this->setStaticProperty(TemplateCompiler::class, 'classMappings', [
                'x-calendar' => [
                    [
                        'className' => 'Components\\Calendar',
                        'filePath' => __FILE__,
                    ],
                    [
                        'className' => 'Lib\\PPIcons\\Calendar',
                        'filePath' => __FILE__,
                    ],
                ],
            ]);

            $method = new \ReflectionMethod(TemplateCompiler::class, 'selectComponentMapping');
            $method->setAccessible(true);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Component x-calendar is ambiguous');

            $method->invoke(null, 'x-calendar');
        } finally {
            @unlink($fixturePath);
        }
    }

    public function testCompileResolvesAliasedHtmlFirstComponentTagsFromUseImports(): void
    {
        $fixturePath = $this->createComponentImportFixture("<?php\n\nuse PP\\Tests\\Unit\\HtmlFirstButtonFixture as MyButton;\n");

        try {
            \Bootstrap::$contentToInclude = $fixturePath;
            PrismaPHPSettings::$classLogFiles = [
                'x-button' => [[
                    'className' => HtmlFirstButtonFixture::class,
                    'filePath' => __FILE__,
                ]],
            ];
            $this->setStaticProperty(TemplateCompiler::class, 'classMappings', []);

            $output = TemplateCompiler::compile('<div><x-my-button>Click Me</x-my-button></div>');

            self::assertStringContainsString('<button', $output);
            self::assertStringContainsString('Click Me', $output);
            self::assertStringNotContainsString('<x-my-button', $output);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function testSelectComponentMappingPrefersAliasedTargetWhenImportsConflict(): void
    {
        $fixturePath = $this->createComponentImportFixture(
            "<?php\n\nuse PP\\Tests\\Unit\\HtmlFirstAsChildButtonFixture;\nuse PP\\Tests\\Unit\\HtmlFirstButtonFixture as MyButton;\n"
        );

        try {
            \Bootstrap::$contentToInclude = $fixturePath;
            $this->setStaticProperty(TemplateCompiler::class, 'classMappings', [
                'x-button' => [
                    [
                        'className' => HtmlFirstAsChildButtonFixture::class,
                        'filePath' => __FILE__,
                    ],
                    [
                        'className' => HtmlFirstButtonFixture::class,
                        'filePath' => __FILE__,
                    ],
                ],
            ]);

            $method = new \ReflectionMethod(TemplateCompiler::class, 'selectComponentMapping');
            $method->setAccessible(true);
            $mapping = $method->invoke(null, 'x-my-button');

            self::assertSame(HtmlFirstButtonFixture::class, $mapping['className']);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function testSelectComponentMappingPrefersDirectImportForCanonicalTagWhenAliasConflicts(): void
    {
        $fixturePath = $this->createComponentImportFixture(
            "<?php\n\nuse PP\\Tests\\Unit\\HtmlFirstAsChildButtonFixture as MyButton;\nuse PP\\Tests\\Unit\\HtmlFirstButtonFixture;\n"
        );

        try {
            \Bootstrap::$contentToInclude = $fixturePath;
            $this->setStaticProperty(TemplateCompiler::class, 'classMappings', [
                'x-button' => [
                    [
                        'className' => HtmlFirstAsChildButtonFixture::class,
                        'filePath' => __FILE__,
                    ],
                    [
                        'className' => HtmlFirstButtonFixture::class,
                        'filePath' => __FILE__,
                    ],
                ],
            ]);

            $method = new \ReflectionMethod(TemplateCompiler::class, 'selectComponentMapping');
            $method->setAccessible(true);
            $mapping = $method->invoke(null, 'x-button');

            self::assertSame(HtmlFirstButtonFixture::class, $mapping['className']);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function testSelectComponentMappingResolvesGroupedUseImportsForCanonicalAndAliasTags(): void
    {
        $fixturePath = $this->createComponentImportFixture(
            "<?php\n\nuse PP\\Tests\\Unit\\{HtmlFirstButtonFixture, HtmlFirstAsChildButtonFixture as MyButton};\n"
        );

        try {
            \Bootstrap::$contentToInclude = $fixturePath;
            $this->setStaticProperty(TemplateCompiler::class, 'classMappings', [
                'x-button' => [
                    [
                        'className' => HtmlFirstAsChildButtonFixture::class,
                        'filePath' => __FILE__,
                    ],
                    [
                        'className' => HtmlFirstButtonFixture::class,
                        'filePath' => __FILE__,
                    ],
                ],
            ]);

            $method = new \ReflectionMethod(TemplateCompiler::class, 'selectComponentMapping');
            $method->setAccessible(true);

            $canonicalMapping = $method->invoke(null, 'x-button');
            $aliasedMapping = $method->invoke(null, 'x-my-button');

            self::assertSame(HtmlFirstButtonFixture::class, $canonicalMapping['className']);
            self::assertSame(HtmlFirstAsChildButtonFixture::class, $aliasedMapping['className']);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function testCompileKeepsPulsePointScriptsRawWithoutCdataWrapper(): void
    {
        $html = '<div><script>const ready = value > 0 && enabled;</script></div>';
        $output = TemplateCompiler::compile($html);

        self::assertStringContainsString('<script>const ready = value > 0 && enabled;</script>', $output);
        self::assertStringNotContainsString('type="text/pp"', $output);
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
        self::assertStringNotContainsString('type="text/pp"', $output);
        self::assertMatchesRegularExpression('/<script[^>]*pp-dynamic-script="81D7D"[^>]*><\/script><\/head>/i', $output);
        self::assertMatchesRegularExpression('/<script[^>]*pp-component="[^"]+"[^>]*>console\.log\(1\)<\/script><\/body>/i', $output);
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

    private function createComponentImportFixture(string $source): string
    {
        $fixturePath = tempnam(sys_get_temp_dir(), 'template-compiler-import-');

        if ($fixturePath === false) {
            $this->fail('Failed to create temporary component fixture.');
        }

        file_put_contents($fixturePath, $source);

        return $fixturePath;
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

final class HtmlFirstCustomAliasFixture extends \PP\PHPX\PHPX
{
    public ?bool $asChild = false;
    public ?string $dataState = null;
    public ?string $onChangeChecked = null;
    public mixed $children = null;

    public function render(): string
    {
        if ($this->asChild) {
            return '<section data-state="'
                . htmlspecialchars((string) $this->dataState, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" data-on-change-checked="'
                . htmlspecialchars((string) $this->onChangeChecked, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">'
                . ($this->children ?? '')
                . '</section>';
        }

        return '<button>' . ($this->children ?? '') . '</button>';
    }
}

final class HtmlFirstEventAliasFixture extends \PP\PHPX\PHPX
{
    public ?string $onClick = null;
    public ?string $onOpenChange = null;
    public mixed $children = null;

    public function render(): string
    {
        return '<button>' . ($this->children ?? '') . '</button>';
    }
}
