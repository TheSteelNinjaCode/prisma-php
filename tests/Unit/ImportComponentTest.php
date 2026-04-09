<?php

declare(strict_types=1);

namespace PP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PP\Attributes\ExposedRegistry;
use PP\ImportComponent;

final class ImportComponentTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->setStaticProperty(ImportComponent::class, 'sections', []);
        $this->setStaticProperty(ImportComponent::class, 'preparedSourceCache', []);
        $this->setStaticProperty(ImportComponent::class, 'registeredExposedComponentMtims', []);
        $this->setStaticProperty(ImportComponent::class, 'compiledRunnerCache', []);
        $this->setStaticProperty(ExposedRegistry::class, 'functions', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $filePath) {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }

        $this->setStaticProperty(ImportComponent::class, 'sections', []);
        $this->setStaticProperty(ImportComponent::class, 'preparedSourceCache', []);
        $this->setStaticProperty(ImportComponent::class, 'registeredExposedComponentMtims', []);
        $this->setStaticProperty(ImportComponent::class, 'compiledRunnerCache', []);
        $this->setStaticProperty(ExposedRegistry::class, 'functions', []);
    }

    public function testRenderReusesCompiledRunnerForFunctionlessComponents(): void
    {
        $filePath = $this->createComponentFile(<<<'PHP'
<?php
$prefix = strtoupper($message);
?>
<div><?= $prefix ?></div>
PHP);

        ob_start();
        ImportComponent::render($filePath, ['message' => 'first']);
        $firstOutput = (string) ob_get_clean();

        ob_start();
        ImportComponent::render($filePath, ['message' => 'second']);
        $secondOutput = (string) ob_get_clean();

        $compiledRunnerCache = $this->getStaticProperty(ImportComponent::class, 'compiledRunnerCache');

        self::assertStringContainsString('>FIRST</div>', $firstOutput);
        self::assertStringContainsString('>SECOND</div>', $secondOutput);
        self::assertCount(1, $compiledRunnerCache);
        self::assertArrayHasKey($filePath, $compiledRunnerCache);
        self::assertTrue(function_exists($compiledRunnerCache[$filePath]['runner']));
    }

    public function testRenderFallsBackWhenComponentUsesTopLevelImports(): void
    {
        $filePath = $this->createComponentFile(<<<'PHP'
<?php
use DateTimeImmutable;

$prefix = DateTimeImmutable::createFromFormat('Y-m-d', '2024-01-01')->format('Y');
?>
<div><?= $prefix ?></div>
PHP);

        ob_start();
        ImportComponent::render($filePath);
        $output = (string) ob_get_clean();

        $compiledRunnerCache = $this->getStaticProperty(ImportComponent::class, 'compiledRunnerCache');
        $preparedSourceCache = $this->getStaticProperty(ImportComponent::class, 'preparedSourceCache');

        self::assertStringContainsString('>2024</div>', $output);
        self::assertArrayHasKey($filePath, $preparedSourceCache);
        self::assertFalse($preparedSourceCache[$filePath]['supportsCompiledRunner']);
        self::assertArrayNotHasKey($filePath, $compiledRunnerCache);
    }

    public function testRenderCachesPreparedSourceAndStillRegistersExposedFunctions(): void
    {
        $filePath = $this->createComponentFile(<<<'PHP'
<?php
use PP\Attributes\Exposed;

#[Exposed]
function ping(): string
{
    return 'pong';
}
?>
<div><?= $message ?></div>
PHP);

        ob_start();
        ImportComponent::render($filePath, ['message' => 'first']);
        $firstOutput = (string) ob_get_clean();

        ob_start();
        ImportComponent::render($filePath, ['message' => 'second']);
        $secondOutput = (string) ob_get_clean();

        $preparedSourceCache = $this->getStaticProperty(ImportComponent::class, 'preparedSourceCache');
        $registeredExposedComponentMtims = $this->getStaticProperty(
            ImportComponent::class,
            'registeredExposedComponentMtims'
        );

        self::assertStringContainsString('pp-component=', $firstOutput);
        self::assertStringContainsString('message="first"', $firstOutput);
        self::assertStringContainsString('>first</div>', $firstOutput);
        self::assertStringContainsString('message="second"', $secondOutput);
        self::assertStringContainsString('>second</div>', $secondOutput);
        self::assertNotNull(ExposedRegistry::resolveFunction('ping'));
        self::assertCount(1, $preparedSourceCache);
        self::assertArrayHasKey($filePath, $preparedSourceCache);
        self::assertSame(['ping'], $preparedSourceCache[$filePath]['exposedFunctionNames']);
        self::assertArrayHasKey($filePath, $registeredExposedComponentMtims);
    }

    private function createComponentFile(string $contents): string
    {
        $filePath = tempnam(sys_get_temp_dir(), 'pp-component-');

        if ($filePath === false) {
            self::fail('Failed to create temporary component file.');
        }

        file_put_contents($filePath, $contents);
        $this->tempFiles[] = $filePath;

        return $filePath;
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