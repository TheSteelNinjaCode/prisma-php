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

    /** @var array<string, array{mtime:int, source:string, hasAttributes:bool, hasNamedFunctions:bool, supportsCompiledRunner:bool, exposedFunctionNames:list<string>, endsInPhpMode:bool, compiledPrelude:string, compiledBody:string, compiledBodyEndsInPhpMode:bool}> */
    private static array $preparedSourceCache = [];

    /** @var array<string, int> */
    private static array $registeredExposedComponentMtims = [];

    /** @var array<string, array{mtime:int, runner:string}> */
    private static array $compiledRunnerCache = [];

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

        $html = self::executePhpComponentIsolated($filePath, $props);

        if (trim($html) === '') {
            throw new RuntimeException("Component rendered empty output: {$filePath}");
        }

        $dom = TemplateCompiler::convertToXml($html);
        $rootEl = self::getSingleRootElement($dom, $filePath);
        $rootEl->setAttribute('pp-component', self::componentIdFromPath($filePath));

        self::applyAttributes($rootEl, $props);

        $newHtml = TemplateCompiler::innerXml($dom);

        self::$sections[$filePath] = [
            'path'  => $filePath,
            'html'  => $newHtml,
            'props' => $props,
        ];

        echo $newHtml;
    }

    public static function sections(): array
    {
        return self::$sections;
    }

    private static function componentIdFromPath(string $filePath): string
    {
        return 's' . base_convert(sprintf('%u', crc32($filePath)), 10, 36);
    }

    private static function executePhpComponentIsolated(string $filePath, array $props): string
    {
        $preparedSource = self::getPreparedSource($filePath);
        $compiledRunner = self::getCompiledRunner($filePath, $preparedSource);
        $shouldRegisterResolvedExposedFunctions = $preparedSource['exposedFunctionNames'] !== []
            && self::shouldRegisterExposedFunctions($filePath, $preparedSource['mtime']);

        if ($compiledRunner !== null) {
            try {
                $html = $compiledRunner($props);

                if ($shouldRegisterResolvedExposedFunctions) {
                    self::registerResolvedExposedFunctions(
                        $preparedSource['exposedFunctionNames'],
                        self::getCompiledNamespace($filePath, $preparedSource['mtime'])
                    );
                    self::$registeredExposedComponentMtims[$filePath] = $preparedSource['mtime'];
                }

                return $html;
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Component execution failed for {$filePath}: " . $e->getMessage(),
                    previous: $e
                );
            }
        }

        $source = $preparedSource['source'];
        $shouldInspectNewFunctions = $preparedSource['hasAttributes']
            && $preparedSource['exposedFunctionNames'] === []
            && self::shouldRegisterExposedFunctions($filePath, $preparedSource['mtime']);

        $ns = 'PP\\ComponentSandbox\\C' . str_replace('.', '_', uniqid('', true));

        $runner = static function (
            string $__code,
            array $__props,
            string $__ns,
            string $__filePath,
            bool $__shouldInspectNewFunctions
        ): string {
            extract($__props, EXTR_SKIP);

            $beforeFns = $__shouldInspectNewFunctions ? get_defined_functions()['user'] : [];

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

            if ($__shouldInspectNewFunctions) {
                $afterFns = get_defined_functions()['user'];
                $newFns = array_values(array_diff($afterFns, $beforeFns));

                foreach ($newFns as $fn) {
                    try {
                        $ref   = new ReflectionFunction($fn);
                        $attrs = $ref->getAttributes(Exposed::class);
                        if (!$attrs) {
                            continue;
                        }

                        $short = $ref->getShortName();

                        if ($ref->getNamespaceName() !== $__ns) {
                            continue;
                        }

                        ExposedRegistry::registerFunction($short, $ref->getName());
                    } catch (Throwable) {
                        // ignore
                    }
                }
            }

            return (string) ob_get_clean();
        };

        $html = $runner($source, $props, $ns, $filePath, $shouldInspectNewFunctions);

        if ($shouldRegisterResolvedExposedFunctions) {
            self::registerResolvedExposedFunctions($preparedSource['exposedFunctionNames'], $ns);
            self::$registeredExposedComponentMtims[$filePath] = $preparedSource['mtime'];
        } elseif ($shouldInspectNewFunctions) {
            self::$registeredExposedComponentMtims[$filePath] = $preparedSource['mtime'];
        }

        return $html;
    }

    /**
    * @return array{mtime:int, source:string, hasAttributes:bool, hasNamedFunctions:bool, supportsCompiledRunner:bool, exposedFunctionNames:list<string>, endsInPhpMode:bool, compiledPrelude:string, compiledBody:string, compiledBodyEndsInPhpMode:bool}
     */
    private static function getPreparedSource(string $filePath): array
    {
        $mtime = (int) (filemtime($filePath) ?: 0);
        $cached = self::$preparedSourceCache[$filePath] ?? null;

        if ($cached !== null && $cached['mtime'] === $mtime) {
            return $cached;
        }

        $source = @file_get_contents($filePath);
        if ($source === false) {
            throw new RuntimeException("Unable to read component file: {$filePath}");
        }

        $source = self::stripPhpOpenTag($source);
        $source = self::stripLeadingDeclareStrictTypes($source);
        $analysis = self::analyzeSource($source);

        $preparedSource = [
            'mtime' => $mtime,
            'source' => $source,
            'hasAttributes' => $analysis['hasAttributes'],
            'hasNamedFunctions' => $analysis['hasNamedFunctions'],
            'supportsCompiledRunner' => $analysis['supportsCompiledRunner'],
            'exposedFunctionNames' => $analysis['exposedFunctionNames'],
            'endsInPhpMode' => $analysis['endsInPhpMode'],
            'compiledPrelude' => $analysis['compiledPrelude'],
            'compiledBody' => $analysis['compiledBody'],
            'compiledBodyEndsInPhpMode' => $analysis['compiledBodyEndsInPhpMode'],
        ];

        self::$preparedSourceCache[$filePath] = $preparedSource;

        return $preparedSource;
    }

    /**
    * @param array{mtime:int, source:string, hasAttributes:bool, hasNamedFunctions:bool, supportsCompiledRunner:bool, exposedFunctionNames:list<string>, endsInPhpMode:bool, compiledPrelude:string, compiledBody:string, compiledBodyEndsInPhpMode:bool} $preparedSource
     * @return callable(array<string, mixed>): string|null
     */
    private static function getCompiledRunner(string $filePath, array $preparedSource): ?callable
    {
        if (!$preparedSource['supportsCompiledRunner']) {
            return null;
        }

        $cached = self::$compiledRunnerCache[$filePath] ?? null;
        if (
            $cached !== null &&
            $cached['mtime'] === $preparedSource['mtime'] &&
            function_exists($cached['runner'])
        ) {
            return $cached['runner'];
        }

        $hash = substr(hash('sha256', $filePath . '|' . $preparedSource['mtime']), 0, 24);
        $namespace = self::getCompiledNamespace($filePath, $preparedSource['mtime']);
        $functionName = '__pp_component_render';
        $runner = $namespace . '\\' . $functionName;

        if (!function_exists($runner)) {
            $compiledPrelude = $preparedSource['compiledPrelude'];
            $compiledSource = $preparedSource['compiledBody'] . "\n";

            if (!$preparedSource['compiledBodyEndsInPhpMode']) {
                $compiledSource .= "<?php\n";
            }

            try {
                eval(
                    "namespace {$namespace};\n" .
                    $compiledPrelude .
                    'function ' . $functionName . '(array $__props): string {' . "\n" .
                    '    extract($__props, EXTR_SKIP);' . "\n" .
                    '    ob_start();' . "\n" .
                    $compiledSource .
                    '    return (string) ob_get_clean();' . "\n" .
                    "}\n"
                );
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Component compilation failed for {$filePath}: " . $e->getMessage(),
                    previous: $e
                );
            }
        }

        self::$compiledRunnerCache[$filePath] = [
            'mtime' => $preparedSource['mtime'],
            'runner' => $runner,
        ];

        return $runner;
    }

    private static function getCompiledNamespace(string $filePath, int $mtime): string
    {
        $hash = substr(hash('sha256', $filePath . '|' . $mtime), 0, 24);

        return 'PP\\ComponentSandbox\\Compiled\\C' . $hash;
    }

    /**
     * @return array{hasAttributes:bool, hasNamedFunctions:bool, supportsCompiledRunner:bool, exposedFunctionNames:list<string>, endsInPhpMode:bool, compiledPrelude:string, compiledBody:string, compiledBodyEndsInPhpMode:bool}
     */
    private static function analyzeSource(string $source): array
    {
        $tokens = token_get_all("<?php\n" . $source);
        $tokenCount = count($tokens);
        $depth = 0;
        $imports = [];
        $pendingAttributes = [];
        $pendingAttributeBuffer = '';
        $hasAttributes = false;
        $hasNamedFunctions = false;
        $supportsCompiledRunner = true;
        $exposedFunctionNames = [];
        $endsInPhpMode = true;
        $compiledPrelude = '';
        $compiledBody = '';

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            $text = self::getTokenText($token);

            if ($index === 0) {
                continue;
            }

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth = max(0, $depth - 1);
                }

                if ($pendingAttributeBuffer !== '') {
                    $pendingAttributeBuffer .= $text;
                } else {
                    $compiledBody .= $text;
                }

                continue;
            }

            if ($token[0] === T_CLOSE_TAG) {
                $endsInPhpMode = false;
                if ($pendingAttributeBuffer !== '') {
                    $pendingAttributeBuffer .= $text;
                } else {
                    $compiledBody .= $text;
                }
                continue;
            }

            if ($token[0] === T_OPEN_TAG || $token[0] === T_OPEN_TAG_WITH_ECHO) {
                $endsInPhpMode = true;
                if ($pendingAttributeBuffer !== '') {
                    $pendingAttributeBuffer .= $text;
                } else {
                    $compiledBody .= $text;
                }
                continue;
            }

            if ($depth !== 0) {
                if ($pendingAttributeBuffer !== '') {
                    $compiledBody .= $pendingAttributeBuffer;
                    $pendingAttributeBuffer = '';
                    $pendingAttributes = [];
                }

                $compiledBody .= $text;
                continue;
            }

            if ($token[0] === T_USE) {
                $statementIndex = $index;
                $statementText = self::collectStatementText($tokens, $statementIndex);
                $imports += self::parseImportedNames($tokens, $index);
                if ($pendingAttributeBuffer !== '') {
                    $compiledBody .= $pendingAttributeBuffer;
                    $pendingAttributeBuffer = '';
                    $pendingAttributes = [];
                }

                $compiledPrelude .= $statementText . "\n";
                continue;
            }

            if ($token[0] === T_CONST) {
                $statementIndex = $index;
                $statementText = self::collectStatementText($tokens, $statementIndex);
                if ($pendingAttributeBuffer !== '') {
                    $compiledBody .= $pendingAttributeBuffer;
                    $pendingAttributeBuffer = '';
                    $pendingAttributes = [];
                }

                $compiledPrelude .= $statementText . "\n";
                continue;
            }

            if (in_array($token[0], [T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $supportsCompiledRunner = false;
                if ($pendingAttributeBuffer !== '') {
                    $compiledBody .= $pendingAttributeBuffer;
                    $pendingAttributeBuffer = '';
                    $pendingAttributes = [];
                }

                $compiledBody .= $text;
                continue;
            }

            if ($token[0] === T_ATTRIBUTE) {
                $hasAttributes = true;
                $attributeIndex = $index;
                $pendingAttributeBuffer .= self::collectAttributeText($tokens, $attributeIndex);
                $pendingAttributes = array_merge(
                    $pendingAttributes,
                    self::parseAttributeNames($tokens, $index)
                );
                continue;
            }

            if ($token[0] !== T_FUNCTION) {
                if ($pendingAttributeBuffer !== '') {
                    if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $pendingAttributeBuffer .= $text;
                        continue;
                    }

                    $compiledBody .= $pendingAttributeBuffer;
                    $pendingAttributeBuffer = '';
                    $pendingAttributes = [];
                }

                $compiledBody .= $text;

                continue;
            }

            $functionName = self::parseNamedFunction($tokens, $index);

            if ($functionName !== null) {
                $hasNamedFunctions = true;

                if (self::hasExposedAttribute($pendingAttributes, $imports)) {
                    $exposedFunctionNames[] = $functionName;
                }

                $compiledPrelude .= $pendingAttributeBuffer;
                $pendingAttributeBuffer = '';
                $pendingAttributes = [];
                $compiledPrelude .= self::collectFunctionText($tokens, $index) . "\n";

                continue;
            }

            if ($pendingAttributeBuffer !== '') {
                $compiledBody .= $pendingAttributeBuffer;
                $pendingAttributeBuffer = '';
                $pendingAttributes = [];
            }

            $compiledBody .= $text;
        }

        if ($pendingAttributeBuffer !== '') {
            $supportsCompiledRunner = false;
            $compiledBody .= $pendingAttributeBuffer;
        }

        $compiledBodyEndsInPhpMode = self::sourceEndsInPhpMode($compiledBody);

        return [
            'hasAttributes' => $hasAttributes,
            'hasNamedFunctions' => $hasNamedFunctions,
            'supportsCompiledRunner' => $supportsCompiledRunner,
            'exposedFunctionNames' => $exposedFunctionNames,
            'endsInPhpMode' => $endsInPhpMode,
            'compiledPrelude' => $compiledPrelude,
            'compiledBody' => $compiledBody,
            'compiledBodyEndsInPhpMode' => $compiledBodyEndsInPhpMode,
        ];
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function parseNamedFunction(array $tokens, int $index): ?string
    {
        $tokenCount = count($tokens);

        for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];

            if (is_string($token)) {
                if ($token === '&') {
                    continue;
                }

                return null;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * @param list<string> $attributeNames
     * @param array<string, string> $imports
     */
    private static function hasExposedAttribute(array $attributeNames, array $imports): bool
    {
        foreach ($attributeNames as $attributeName) {
            $normalizedName = ltrim($attributeName, '\\');
            $resolvedName = $imports[$normalizedName] ?? $normalizedName;

            if (
                strcasecmp($normalizedName, 'Exposed') === 0 ||
                strcasecmp($resolvedName, Exposed::class) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $functionNames
     */
    private static function registerResolvedExposedFunctions(array $functionNames, string $namespace): void
    {
        foreach ($functionNames as $functionName) {
            ExposedRegistry::registerFunction($functionName, $namespace . '\\' . $functionName);
        }
    }

    private static function normalizeImportAlias(string $name, ?string $alias): string
    {
        if ($alias !== null && $alias !== '') {
            return $alias;
        }

        $parts = explode('\\', trim($name, '\\'));

        return end($parts) ?: $name;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function collectStatementText(array $tokens, int &$index): string
    {
        $text = '';
        $tokenCount = count($tokens);

        for ($cursor = $index; $cursor < $tokenCount; $cursor++) {
            $text .= self::getTokenText($tokens[$cursor]);

            if ($tokens[$cursor] === ';') {
                $index = $cursor;
                break;
            }
        }

        return $text;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function collectAttributeText(array $tokens, int &$index): string
    {
        $text = '';
        $tokenCount = count($tokens);

        for ($cursor = $index; $cursor < $tokenCount; $cursor++) {
            $text .= self::getTokenText($tokens[$cursor]);

            if ($tokens[$cursor] === ']') {
                $index = $cursor;
                break;
            }
        }

        return $text;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function collectFunctionText(array $tokens, int &$index): string
    {
        $text = '';
        $tokenCount = count($tokens);
        $braceDepth = 0;
        $startedBody = false;

        for ($cursor = $index; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];
            $text .= self::getTokenText($token);

            if ($token === '{') {
                $braceDepth++;
                $startedBody = true;
            } elseif ($token === '}') {
                $braceDepth--;

                if ($startedBody && $braceDepth === 0) {
                    $index = $cursor;
                    break;
                }
            }
        }

        return $text;
    }

    private static function getTokenText(mixed $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function nextMeaningfulToken(array $tokens, int $index): mixed
    {
        $tokenCount = count($tokens);

        for ($cursor = $index; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private static function sourceEndsInPhpMode(string $source): bool
    {
        $tokens = token_get_all("<?php\n" . $source);
        $inPhpMode = true;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_CLOSE_TAG) {
                $inPhpMode = false;
                continue;
            }

            if ($token[0] === T_OPEN_TAG || $token[0] === T_OPEN_TAG_WITH_ECHO) {
                $inPhpMode = true;
            }
        }

        return $inPhpMode;
    }

    private static function isQualifiedNameToken(int $tokenId): bool
    {
        return in_array(
            $tokenId,
            array_filter([
                T_STRING,
                defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
                defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
                defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : null,
            ]),
            true
        );
    }

    /**
     * @param list<mixed> $tokens
     * @return array<string, string>
     */
    private static function parseImportedNames(array $tokens, int &$index): array
    {
        $imports = [];
        $tokenCount = count($tokens);
        $currentName = '';
        $currentAlias = null;

        for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];

            if (is_string($token)) {
                if ($token === ',') {
                    if ($currentName !== '') {
                        $imports[self::normalizeImportAlias($currentName, $currentAlias)] = ltrim($currentName, '\\');
                    }

                    $currentName = '';
                    $currentAlias = null;
                    continue;
                }

                if ($token === ';') {
                    if ($currentName !== '') {
                        $imports[self::normalizeImportAlias($currentName, $currentAlias)] = ltrim($currentName, '\\');
                    }

                    $index = $cursor;
                    break;
                }

                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token[0] === T_AS) {
                $aliasToken = self::nextMeaningfulToken($tokens, $cursor + 1);
                $currentAlias = is_array($aliasToken) ? $aliasToken[1] : null;
                continue;
            }

            if (self::isQualifiedNameToken($token[0])) {
                $currentName .= $token[1];
            }
        }

        return $imports;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<string>
     */
    private static function parseAttributeNames(array $tokens, int &$index): array
    {
        $attributes = [];
        $tokenCount = count($tokens);
        $currentName = '';

        for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];

            if (is_string($token)) {
                if (($token === ',' || $token === '(' || $token === ']') && $currentName !== '') {
                    $attributes[] = $currentName;
                    $currentName = '';
                }

                if ($token === ']') {
                    $index = $cursor;
                    break;
                }

                continue;
            }

            if (self::isQualifiedNameToken($token[0])) {
                $currentName .= $token[1];
            }
        }

        return $attributes;
    }

    private static function shouldRegisterExposedFunctions(string $filePath, int $mtime): bool
    {
        return (self::$registeredExposedComponentMtims[$filePath] ?? null) !== $mtime;
    }

    private static function stripPhpOpenTag(string $source): string
    {
        $trimmed = ltrim($source);

        if (str_starts_with($trimmed, '<?php')) {
            return preg_replace('/^\s*<\?php\b/i', '', $source, 1) ?? $source;
        }

        if (str_starts_with($trimmed, '<?=') || str_starts_with($trimmed, '<?=')) {
            $out = preg_replace('/^\s*<\?(=)/', 'echo ', $source, 1);
            return $out ?? $source;
        }

        return "?>\n" . $source;
    }

    private static function stripLeadingDeclareStrictTypes(string $source): string
    {
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
