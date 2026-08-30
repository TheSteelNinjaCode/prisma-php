<?php

declare(strict_types=1);

$autoloadPath = null;

foreach ([
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
] as $candidate) {
    if (is_file($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if ($autoloadPath === null) {
    throw new RuntimeException('Unable to locate Composer autoload.php for runtime benchmarks.');
}

require $autoloadPath;

use PP\Attributes\ExposedRegistry;
use PP\ImportComponent;
use PP\MainLayout;
use PP\PHPX\PHPX;
use PP\PHPX\TemplateCompiler;
use PP\PrismaPHPSettings;
use PP\Request;
use PP\Set;

PrismaPHPSettings::$classLogFiles = [];

$cleanupFiles = [];

register_shutdown_function(static function () use (&$cleanupFiles): void {
    foreach ($cleanupFiles as $filePath) {
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }
});

resetStaticProperty(TemplateCompiler::class, 'compiledCache', []);
resetStaticProperty(TemplateCompiler::class, 'cacheStats', []);
resetStaticProperty(TemplateCompiler::class, 'camelToKebabCache', []);
resetStaticProperty(PHPX::class, 'publicPropertyTypeCache', []);
resetStaticProperty(PHPX::class, 'publicPropertyTypeInfoCache', []);
resetStaticProperty(ImportComponent::class, 'sections', []);
resetStaticProperty(ImportComponent::class, 'preparedSourceCache', []);
resetStaticProperty(ImportComponent::class, 'registeredExposedComponentMtims', []);
resetStaticProperty(ImportComponent::class, 'compiledRunnerCache', []);
resetStaticProperty(ImportComponent::class, 'componentIdCache', []);
resetStaticProperty(ImportComponent::class, 'compiledNamespaceCache', []);
resetStaticProperty(ExposedRegistry::class, 'functions', []);
resetStaticProperty(MainLayout::class, 'headScripts', new Set());
resetStaticProperty(MainLayout::class, 'footerScripts', []);
resetStaticProperty(MainLayout::class, 'processedScripts', []);
resetStaticProperty(MainLayout::class, 'footerComponentCounter', 0);
resetStaticProperty(MainLayout::class, 'headScriptsOutputCache', null);
resetStaticProperty(MainLayout::class, 'footerScriptsOutputCache', null);
resetStaticProperty(MainLayout::class, 'systemPropsCache', null);
resetStaticProperty(MainLayout::class, 'footerScriptHashCache', []);
resetStaticProperty(MainLayout::class, 'footerScriptAttributesCache', []);
resetStaticProperty(MainLayout::class, 'preparedHeadScriptCache', []);

$plainComponent = createTempComponentFile(<<<'PHP'
<?php ?>
<div class="card"><?= $message ?></div>
PHP, $cleanupFiles);

$exposedComponent = createTempComponentFile(<<<'PHP'
<?php
use PP\Attributes\Exposed;

#[Exposed]
function ping(): string
{
    return 'pong';
}
?>
<section><?= $message ?></section>
PHP, $cleanupFiles);

final class BenchPHPX extends PHPX
{
    public int $count = 0;
    public ?string $label = null;
}

echo "Prisma PHP runtime benchmarks\n";
echo str_repeat('=', 72) . "\n";

benchmark('phpx_constructor_cached_props', 100000, static function (): void {
    new BenchPHPX(['count' => '7', 'label' => 'alpha']);
});

$template = '<div><span>Hello</span><strong>World</strong></div>';

benchmark('template_compile_cold', 1000, static function () use ($template): void {
    TemplateCompiler::compile($template . uniqid('', true));
});

benchmark('template_compile_hot_cache', 100000, static function () use ($template): void {
    TemplateCompiler::compile($template);
});

$compileComponentHtml = new ReflectionMethod(TemplateCompiler::class, 'compileComponentHtml');
$compileComponentHtml->setAccessible(true);

benchmark('template_compile_component_html', 20000, static function () use ($compileComponentHtml): void {
    $compileComponentHtml->invoke(
        null,
        '<button dataFoo="{bar}"></button>',
        's1',
        ['dataBar' => '{baz}', 'title' => 'Save']
    );
});

$scopedEventHtml = '<div><template pp-owner="child"><button onClick="{save}"></button></template><button onClick="{save}"></button><button onClick="{skip}"></button></div>';

benchmark('template_compile_component_html_events', 20000, static function () use ($compileComponentHtml, $scopedEventHtml): void {
    $compileComponentHtml->invoke(
        null,
        $scopedEventHtml,
        's1',
        ['onClick' => '{save}'],
        'parent'
    );
});

benchmark('import_component_plain', 5000, static function () use ($plainComponent): void {
    ob_start();
    ImportComponent::render($plainComponent, ['message' => 'hello']);
    ob_end_clean();
});

benchmark('import_component_with_exposed', 5000, static function () use ($exposedComponent): void {
    ob_start();
    ImportComponent::render($exposedComponent, ['message' => 'hello']);
    ob_end_clean();
});

benchmark('import_component_with_exposed_register', 1000, static function () use ($exposedComponent): void {
    resetStaticProperty(ImportComponent::class, 'registeredExposedComponentMtims', []);
    ob_start();
    ImportComponent::render($exposedComponent, ['message' => 'hello']);
    ob_end_clean();
});

$footerScript = '<script dataFoo="{bar}">console.log(1)</script>';

benchmark('mainlayout_footer_roundtrip', 50000, static function () use ($footerScript): void {
    MainLayout::clearFooterScripts();
    MainLayout::addFooterScript($footerScript);
    MainLayout::outputFooterScripts();
});

$headScript = '<script src="/app.js"></script>';

benchmark('mainlayout_head_roundtrip', 50000, static function () use ($headScript): void {
    MainLayout::clearHeadScripts();
    MainLayout::addHeadScript($headScript);
    MainLayout::outputHeadScripts();
});

$requestGetParams = new ReflectionMethod(Request::class, 'getParams');
$requestGetParams->setAccessible(true);

benchmark('request_get_params_json_cached', 50000, static function () use ($requestGetParams): void {
    Request::$method = 'POST';
    Request::$contentType = 'application/json';
    Request::$data = null;
    resetStaticProperty(Request::class, 'rawInput', '{"name":"Ada"}');
    resetStaticProperty(Request::class, 'rawInputLoaded', true);
    $requestGetParams->invoke(null);
});

benchmark('request_get_params_form_cached', 50000, static function () use ($requestGetParams): void {
    Request::$method = 'POST';
    Request::$contentType = 'application/x-www-form-urlencoded';
    Request::$data = null;
    resetStaticProperty(Request::class, 'rawInput', 'name=Ada&role=admin');
    resetStaticProperty(Request::class, 'rawInputLoaded', true);
    $requestGetParams->invoke(null);
});

function benchmark(string $name, int $iterations, callable $callback): void
{
    $callback();

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $callback();
    }

    $elapsedNs = hrtime(true) - $start;
    $elapsedMs = $elapsedNs / 1_000_000;
    $averageUs = $elapsedNs / $iterations / 1_000;
    $opsPerSecond = $iterations / max($elapsedNs / 1_000_000_000, 0.000001);

    printf(
        "%-32s %10d iters  %10.3f ms total  %10.3f us/op  %10.0f ops/s\n",
        $name,
        $iterations,
        $elapsedMs,
        $averageUs,
        $opsPerSecond
    );
}

function createTempComponentFile(string $contents, array &$cleanupFiles): string
{
    $filePath = tempnam(sys_get_temp_dir(), 'pp-bench-');

    if ($filePath === false) {
        throw new RuntimeException('Failed to create temporary benchmark component file.');
    }

    file_put_contents($filePath, $contents);
    $cleanupFiles[] = $filePath;

    return $filePath;
}

function resetStaticProperty(string $className, string $propertyName, mixed $value): void
{
    $reflection = new ReflectionClass($className);
    $property = $reflection->getProperty($propertyName);
    $property->setValue($value);
}