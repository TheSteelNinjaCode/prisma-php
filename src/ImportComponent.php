<?php

declare(strict_types=1);

namespace PP;

use DOMDocument;
use DOMElement;
use RuntimeException;
use PP\PHPX\TemplateCompiler;
use ReflectionFunction;
use PP\Attributes\Exposed;
use PP\Attributes\ExposedRegistry;
use Throwable;

final class ImportComponent
{
    /** @var array<string, array{path:string, html:string, props:array<string,mixed>}> */
    private static array $sections = [];

    /**
     * Render a PHP component file by executing it in an isolated namespace,
     * then inject pp-component + props into the rendered root element.
     *
     * @param string $filePath
     * @param array<string,mixed> $props
     */
    public static function render(string $filePath, array $props = []): void
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("Component file not found: {$filePath}");
        }

        // 1) Execute PHP component safely (isolated namespace) -> HTML output
        $html = self::executePhpComponentIsolated($filePath, $props);

        if (trim($html) === '') {
            throw new RuntimeException("Component rendered empty output: {$filePath}");
        }

        // 2) Parse rendered output as XML fragment
        $dom = TemplateCompiler::convertToXml($html);

        // 3) Enforce single root
        $rootEl = self::getSingleRootElement($dom, $filePath);

        // 4) Inject pp-component + props
        $rootEl->setAttribute('pp-component', self::componentIdFromPath($filePath));
        self::applyAttributes($rootEl, $props);

        // 5) Serialize final HTML
        $newHtml = TemplateCompiler::innerXml($dom);

        self::$sections[$filePath] = [
            'path'  => $filePath,
            'html'  => $newHtml,
            'props' => $props,
        ];

        echo $newHtml;
    }

    public static function import(string $filePath, array $props = []): void
    {
        self::render($filePath, $props);
    }

    public static function sections(): array
    {
        return self::$sections;
    }

    private static function componentIdFromPath(string $filePath): string
    {
        return 's' . base_convert(sprintf('%u', crc32($filePath)), 10, 36);
    }

    /**
     * Execute a PHP file in a unique namespace to avoid function redeclare collisions.
     *
     * IMPORTANT:
     * - Props are extracted as local variables.
     * - `use` statements in the component file keep working.
     * - `declare(strict_types=1);` is removed from the component source before eval
     *   because eval'd code cannot safely contain file-level declare in this context.
     *
     * @param array<string,mixed> $props
     */
    private static function executePhpComponentIsolated(string $filePath, array $props): string
    {
        $source = @file_get_contents($filePath);
        if ($source === false) {
            throw new RuntimeException("Unable to read component file: {$filePath}");
        }

        $source = self::stripPhpOpenTag($source);
        $source = self::stripLeadingDeclareStrictTypes($source);

        $ns = 'PP\\ComponentSandbox\\C' . str_replace('.', '_', uniqid('', true));

        $runner = static function (string $__code, array $__props, string $__ns, string $__filePath): string {
            extract($__props, EXTR_SKIP);

            $beforeFns = get_defined_functions()['user'];

            ob_start();
            try {
                $__wrapped = "namespace {$__ns};\n" . $__code;
                eval($__wrapped);
            } catch (Throwable $e) {
                ob_end_clean();
                throw new RuntimeException(
                    "Component execution failed for {$__filePath}: " . $e->getMessage(),
                    previous: $e
                );
            }

            $afterFns = get_defined_functions()['user'];
            $newFns   = array_values(array_diff($afterFns, $beforeFns));

            foreach ($newFns as $fn) {
                try {
                    $ref   = new ReflectionFunction($fn);
                    $attrs = $ref->getAttributes(Exposed::class);
                    if (!$attrs) continue;

                    $short = $ref->getShortName();

                    if ($ref->getNamespaceName() !== $__ns) continue;

                    ExposedRegistry::registerFunction($short, $ref->getName());
                } catch (Throwable) {
                    // ignore
                }
            }

            return (string) ob_get_clean();
        };

        return $runner($source, $props, $ns, $filePath);
    }

    private static function stripPhpOpenTag(string $source): string
    {
        $trimmed = ltrim($source);

        if (str_starts_with($trimmed, '<?php')) {
            return preg_replace('/^\s*<\?php\b/i', '', $source, 1) ?? $source;
        }

        // If file starts without <?php, still allow raw template-ish PHP/HTML mixture
        return $source;
    }

    private static function stripLeadingDeclareStrictTypes(string $source): string
    {
        // Remove only a leading declare(strict_types=1); if present.
        // Keep other code intact.
        $out = preg_replace(
            '/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/i',
            '',
            $source,
            1
        );

        return $out ?? $source;
    }

    private static function getSingleRootElement(DOMDocument $dom, string $filePath): DOMElement
    {
        $wrapper = $dom->documentElement;

        if (!$wrapper) {
            throw new RuntimeException("Invalid XML wrapper while importing: {$filePath}");
        }

        $elements = [];
        foreach ($wrapper->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        if (count($elements) !== 1) {
            $found = count($elements);
            throw new RuntimeException(
                "ImportComponent requires EXACTLY one root element. Found {$found} root element(s) in: {$filePath}"
            );
        }

        return $elements[0];
    }

    /**
     * @param array<string,mixed> $props
     */
    private static function applyAttributes(DOMElement $el, array $props): void
    {
        foreach ($props as $key => $value) {
            if (!is_string($key) || $key === '' || $value === null) {
                continue;
            }

            $attrName  = $key;
            $attrValue = self::serializePropValue($value);

            if (TemplateCompiler::containsMustacheSyntax($attrValue)) {
                $attrName = TemplateCompiler::camelToKebab($attrName);
            }

            $el->setAttribute($attrName, $attrValue);
        }
    }

    private static function serializePropValue(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_scalar($value)) return (string) $value;

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Failed to JSON-encode component prop.');
            }
            return $json;
        }

        return (string) $value;
    }
}
