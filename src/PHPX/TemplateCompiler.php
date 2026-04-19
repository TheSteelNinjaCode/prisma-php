<?php

declare(strict_types=1);

namespace PP\PHPX;

use PP\PrismaPHPSettings;
use PP\MainLayout;
use DOMDocument;
use DOMElement;
use DOMComment;
use DOMNode;
use DOMText;
use RuntimeException;
use Bootstrap;
use LibXMLError;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use PP\PHPX\Exceptions\ComponentValidationException;
use InvalidArgumentException;

class TemplateCompiler
{
    private const COMPONENT_TAG_REGEX = '/<\/*[A-Z][\w-]*/u';
    private const SELF_CLOSING_REGEX = '/<([a-z0-9-]+)([^>]*)\/>/i';
    private const COMPONENT_ATTRIBUTE = 'pp-component';
    private const HEAD_PATTERNS = [
        'open' => '/(<head\b[^>]*>)/i',
        'close' => '/(<\/head\s*>)/i',
    ];
    private const BODY_PATTERNS = [
        'open' => '/<body([^>]*)>/i',
        'close' => '/(<\/body\s*>)/i',
    ];
    private const LITERAL_TEXT_TAGS = [
        'code' => true,
        'pre' => true,
        'samp' => true,
        'kbd' => true,
        'var' => true,
    ];
    private const SYSTEM_PROPS = [
        'children' => true,
        'key' => true,
        'pp-component' => true,
        'pp-for' => true,
        'pp-spread' => true,
        'pp-ref' => true,
    ];
    private const SELF_CLOSING_TAGS = [
        'area' => true,
        'base' => true,
        'br' => true,
        'col' => true,
        'command' => true,
        'embed' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'keygen' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
    ];
    private const SCRIPT_TYPES = [
        '' => true,
        'text/javascript' => true,
        'application/javascript' => true,
        'module' => true,
        'text/pp' => true,
    ];

    private static array $classMappings = [];
    private static array $reflectionCache = [];
    private static array $sectionStack = [];
    private static int $compileDepth = 0;
    private static array $componentInstanceCounts = [];
    private static array $contextStack = [];
    private static array $compiledCache = [];
    private static bool $cacheEnabled = true;
    private static ?DOMDocument $reusableDom = null;
    private static array $compiledPatterns = [];
    private static int $maxCacheSize = 100;
    private static array $cacheStats = [];
    private static array $componentFileStack = [];
    private static array $camelToKebabCache = [];
    private static array $componentPropMetadataCache = [];

    public static function compile(string $templateContent): string
    {
        if (!self::$cacheEnabled) {
            return self::compileInternal($templateContent);
        }

        $hash = md5($templateContent);

        if (isset(self::$compiledCache[$hash])) {
            self::$cacheStats[$hash]['hits']++;
            return self::$compiledCache[$hash];
        }

        if (count(self::$compiledCache) >= self::$maxCacheSize) {
            $leastUsed = self::findLeastUsedCacheKey();

            if ($leastUsed !== null) {
            unset(self::$compiledCache[$leastUsed]);
            unset(self::$cacheStats[$leastUsed]);
            }
        }

        $compiled = self::compileInternal($templateContent);
        self::$compiledCache[$hash] = $compiled;
        self::$cacheStats[$hash] = ['hits' => 0, 'created' => time()];

        return $compiled;
    }

    private static function findLeastUsedCacheKey(): ?string
    {
        $leastUsedKey = null;
        $leastHits = null;
        $oldestTimestamp = null;

        foreach (self::$cacheStats as $cacheKey => $stats) {
            $hits = $stats['hits'] ?? 0;
            $created = $stats['created'] ?? PHP_INT_MAX;

            if (
                $leastUsedKey === null ||
                $hits < $leastHits ||
                ($hits === $leastHits && $created < $oldestTimestamp)
            ) {
                $leastUsedKey = $cacheKey;
                $leastHits = $hits;
                $oldestTimestamp = $created;
            }
        }

        return $leastUsedKey;
    }

    private static function compileInternal(string $templateContent): string
    {
        if (self::$compileDepth === 0) {
            self::$componentInstanceCounts = [];
            self::$contextStack = ['app'];
            self::$componentFileStack = [];
        }
        self::$compileDepth++;

        try {
            if (empty(self::$classMappings)) {
                self::initializeClassMappings();
            }

            $dom = self::convertToXml($templateContent);
            return self::processChildNodes($dom->documentElement->childNodes);
        } finally {
            self::$compileDepth--;
        }
    }

    public static function scopeRouteRoot(string $htmlContent, string $filePath): string
    {
        $componentId = self::routeComponentIdFromPath($filePath);
        $fastScopedHtml = self::tryScopeRouteRootWithoutDom($htmlContent, $componentId);

        if ($fastScopedHtml !== null) {
            return $fastScopedHtml;
        }

        $dom = self::createDomForSingleRootValidation($htmlContent, $filePath, 'Route file');
        $rootElement = self::getSingleRootElementForValidation($dom, $filePath, 'Route file');

        if (trim($rootElement->getAttribute(self::COMPONENT_ATTRIBUTE)) !== '') {
            return $htmlContent;
        }

        $rootElement->setAttribute(self::COMPONENT_ATTRIBUTE, $componentId);

        return self::innerXml($dom);
    }

    public static function validateSingleRootHtml(
        string $htmlContent,
        string $filePath,
        string $contextLabel = 'Template'
    ): void {
        if (self::analyzeSingleRootHtml($htmlContent) !== null) {
            return;
        }

        $dom = self::createDomForSingleRootValidation($htmlContent, $filePath, $contextLabel);
        self::getSingleRootElementForValidation($dom, $filePath, $contextLabel);
    }

    public static function injectDynamicContent(string $htmlContent): string
    {
        $headOpenPos = stripos($htmlContent, '<head');
        if ($headOpenPos !== false) {
            $headOpenEnd = self::findHtmlTagEnd($htmlContent, $headOpenPos);

            if ($headOpenEnd !== null) {
                $metadata = MainLayout::outputMetadata();

                if ($metadata !== '') {
                    $htmlContent = substr($htmlContent, 0, $headOpenEnd + 1)
                        . $metadata
                        . substr($htmlContent, $headOpenEnd + 1);
                }
            }
        }

        $headClosePos = stripos($htmlContent, '</head');
        if ($headClosePos !== false) {
            $headScripts = MainLayout::outputHeadScripts();

            if ($headScripts !== '') {
                $htmlContent = substr($htmlContent, 0, $headClosePos)
                    . $headScripts
                    . substr($htmlContent, $headClosePos);
            }
        }

        $bodyClosePos = stripos($htmlContent, '</body');
        if ($bodyClosePos !== false) {
            $footerScripts = MainLayout::outputFooterScripts();

            if ($footerScripts !== '') {
                $htmlContent = substr($htmlContent, 0, $bodyClosePos)
                    . $footerScripts
                    . substr($htmlContent, $bodyClosePos);
            }
        }

        return $htmlContent;
    }

    public static function convertToXml(string $templateContent): DOMDocument
    {
        $content = self::processContentForXml($templateContent);
        $xml = "<root>{$content}</root>";

        return self::createDomFromXml($xml);
    }

    private static function processContentForXml(string $content): string
    {
        return self::escapeAttributeAngles(
            self::escapeLiteralTextContent(
                self::escapeAmpersands(
                    self::protectCurlyNumericEntities(
                        self::normalizeNamedEntities(
                            self::escapeMustacheOperators(
                                self::protectInlineScripts($content)
                            )
                        )
                    )
                )
            )
        );
    }

    private static function protectCurlyNumericEntities(string $html): string
    {
        if (!str_contains($html, '&#')) {
            return $html;
        }

        return preg_replace_callback(
            self::getPattern('numeric_entity'),
            static function (array $m): string {
                $num = $m[1];

                $isHex = ($num[0] === 'x' || $num[0] === 'X');
                $codepoint = $isHex
                    ? hexdec(substr($num, 1))
                    : (int)$num;

                if ($codepoint === 123 || $codepoint === 125) {
                    return '&amp;#' . $num . ';';
                }

                return $m[0];
            },
            $html
        ) ?? $html;
    }


    private static function escapeMustacheOperators(string $content): string
    {
        if (!str_contains($content, '{')) {
            return $content;
        }

        return self::processCDataAwareParts(
            $content,
            static function (string $part): string {
                return preg_replace_callback(
                    self::getPattern('mustache'),
                    static function (array $matches): string {
                        if (!str_contains($matches[1], '<') && !str_contains($matches[1], '>')) {
                            return $matches[0];
                        }

                        $expression = str_replace(['<', '>'], ['&lt;', '&gt;'], $matches[1]);
                        return '{' . $expression . '}';
                    },
                    $part
                );
            }
        );
    }

    private static function createDomFromXml(string $xml): DOMDocument
    {
        if (self::$reusableDom === null) {
            self::$reusableDom = new DOMDocument('1.0', 'UTF-8');
        }

        $dom = clone self::$reusableDom;
        libxml_use_internal_errors(true);

        if (!$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT)) {
            $errors = self::getXmlErrors();
            libxml_use_internal_errors(false);
            throw new RuntimeException('XML Parsing Failed: ' . implode('; ', $errors));
        }

        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $dom;
    }

    private static function processChildNodes($childNodes): string
    {
        $output = '';
        foreach ($childNodes as $child) {
            $output .= self::processNode($child);
        }

        return $output;
    }

    private static function getPattern(string $key): string
    {
        if (!isset(self::$compiledPatterns[$key])) {
            self::$compiledPatterns[$key] = match ($key) {
                // Script patterns
                'script' => '#<script\b([^>]*?)>(.*?)</script>#is',
                'script_src' => '/\bsrc\s*=/i',
                'script_type' => '/\btype\s*=\s*([\'"]?)([^\'"\s>]+)/i',

                // Mustache and entities
                'mustache' => '/\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/',
                'named_entity' => '/&([a-zA-Z][a-zA-Z0-9]+);/',
                'numeric_entity' => '/&#(x?[0-9A-Fa-f]+);/i',
                'unescaped_ampersand' => '/&(?![a-zA-Z][A-Za-z0-9]*;|#[0-9]+;|#x[0-9A-Fa-f]+;)/',

                // Attributes and tags
                'attribute' => '/(\s[\w:-]+=)([\'"])(.*?)\2/s',
                'literal_text_tags' => '/(<(?:code|pre|samp|kbd|var)\b[^>]*>)(.*?)(<\/(?:code|pre|samp|kbd|var)>)/is',
                'literal_text_operators' => '/(\s|^|,)([<>]=?)(\s|$|,)/',

                // CDATA
                'cdata_split' => '/(<!\[CDATA\[[\s\S]*?\]\]>)/',
                'cdata_start' => '/^<!\[CDATA\[/',

                default => throw new \InvalidArgumentException("Unknown pattern key: $key")
            };
        }

        return self::$compiledPatterns[$key];
    }

    private static function escapeAmpersands(string $content): string
    {
        if (!str_contains($content, '&')) {
            return $content;
        }

        return self::processCDataAwareParts(
            $content,
            static fn(string $part): string => preg_replace(
                self::getPattern('unescaped_ampersand'),
                '&amp;',
                $part
            )
        );
    }

    private static function escapeAttributeAngles(string $html): string
    {
        if (!preg_match('/\s[\w:-]+\s*=\s*(["\'])[^"\']*[<>][^"\']*\1/s', $html)) {
            return $html;
        }

        return self::processCDataAwareParts(
            $html,
            static function (string $part): string {
                return preg_replace_callback(
                    self::getPattern('attribute'),
                    static function (array $m): string {
                        if (!str_contains($m[3], '<') && !str_contains($m[3], '>')) {
                            return $m[0];
                        }

                        return $m[1] . $m[2] .
                            str_replace(['<', '>'], ['&lt;', '&gt;'], $m[3]) . $m[2];
                    },
                    $part
                );
            }
        );
    }

    private static function escapeLiteralTextContent(string $content): string
    {
        static $quickCheck = null;
        if ($quickCheck === null) {
            $quickCheck = '/<(?:code|pre|samp|kbd|var)\b/i';
        }

        if (!preg_match($quickCheck, $content)) {
            return $content;
        }

        return self::processCDataAwareParts(
            $content,
            static function (string $part): string {
                return preg_replace_callback(
                    self::getPattern('literal_text_tags'),
                    static function (array $matches): string {
                        $openTag = $matches[1];
                        $textContent = $matches[2];
                        $closeTag = $matches[3];

                        if (!str_contains($textContent, '<') && !str_contains($textContent, '>')) {
                            return $matches[0];
                        }

                        $escapedContent = preg_replace_callback(
                            self::getPattern('literal_text_operators'),
                            static function (array $match): string {
                                $operator = $match[2];
                                $escapedOp = str_replace(['<', '>'], ['&lt;', '&gt;'], $operator);
                                return $match[1] . $escapedOp . $match[3];
                            },
                            $textContent
                        );

                        return $openTag . $escapedContent . $closeTag;
                    },
                    $part
                );
            }
        );
    }

    private static function normalizeNamedEntities(string $html): string
    {
        if (!str_contains($html, '&')) {
            return $html;
        }

        static $hasMbOrd = null;
        if ($hasMbOrd === null) {
            $hasMbOrd = function_exists('mb_ord');
        }

        return self::processCDataAwareParts(
            $html,
            static function (string $part) use ($hasMbOrd): string {
                if (!preg_match('/&[a-zA-Z]/', $part)) {
                    return $part;
                }

                return preg_replace_callback(
                    self::getPattern('named_entity'),
                    static function (array $m) use ($hasMbOrd): string {
                        $decoded = html_entity_decode($m[0], ENT_HTML5, 'UTF-8');
                        if ($decoded === $m[0]) {
                            return $m[0];
                        }

                        $code = $hasMbOrd
                            ? mb_ord($decoded, 'UTF-8')
                            : unpack('N', mb_convert_encoding($decoded, 'UCS-4BE', 'UTF-8'))[1];

                        return '&#' . $code . ';';
                    },
                    $part
                );
            }
        );
    }

    private static function processCDataAwareParts(string $content, callable $processor): string
    {
        if (!str_contains($content, '<![CDATA[')) {
            return $processor($content);
        }

        $parts = preg_split(
            self::getPattern('cdata_split'),
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($parts === false) {
            return $content;
        }

        foreach ($parts as $i => $part) {
            if ($part !== '' && !preg_match(self::getPattern('cdata_start'), $part)) {
                $parts[$i] = $processor($part);
            }
        }

        return implode('', $parts);
    }

    private static function protectInlineScripts(string $html): string
    {
        if (!str_contains($html, '<script')) {
            return $html;
        }

        $callback = static function (array $m): string {
            if (preg_match(self::getPattern('script_src'), $m[1])) {
                return $m[0];
            }

            if (str_contains($m[2], '<![CDATA[')) {
                return $m[0];
            }

            $type = self::extractScriptType($m[1]);
            if (!isset(self::SCRIPT_TYPES[$type])) {
                return $m[0];
            }

            $code = str_replace(']]>', ']]]]><![CDATA[>', $m[2]);
            return "<script{$m[1]}><![CDATA[\n{$code}\n]]></script>";
        };

        if (preg_match('/^(.*?<body\b[^>]*>)(.*?)(<\/body>.*)$/is', $html, $parts)) {
            [, $beforeBody, $body, $afterBody] = $parts;
            return $beforeBody . self::processScriptsInContent($body, $callback) . $afterBody;
        }

        return self::processScriptsInContent($html, $callback);
    }

    private static function extractScriptType(string $attributes): string
    {
        if (preg_match(self::getPattern('script_type'), $attributes, $matches)) {
            return strtolower($matches[2]);
        }
        return '';
    }

    private static function processScriptsInContent(string $content, callable $callback): string
    {
        return preg_replace_callback(self::getPattern('script'), $callback, $content) ?? $content;
    }

    protected static function processNode(DOMNode $node): string
    {
        return match (true) {
            $node instanceof DOMText => self::processTextNode($node),
            $node instanceof DOMElement => self::processElementNode($node),
            $node instanceof DOMComment => "<!--{$node->textContent}-->",
            default => $node->textContent,
        };
    }

    private static function processElementNode(DOMElement $node): string
    {
        $tag = strtolower($node->nodeName);
        $pushed = false;

        if ($tag === 'script' && !$node->hasAttribute('src') && !$node->hasAttribute('type')) {
            $node->setAttribute('type', 'text/pp');
        }

        if ($node->hasAttribute(self::COMPONENT_ATTRIBUTE)) {
            $componentId = $node->getAttribute(self::COMPONENT_ATTRIBUTE);
            self::$sectionStack[] = $componentId;
            self::$contextStack[] = $componentId;
            $pushed = true;
        }

        try {
            if (isset(self::$classMappings[$node->nodeName])) {
                return self::renderComponent(
                    $node,
                    $node->nodeName,
                    self::getNodeAttributes($node)
                );
            }

            if (preg_match('/^[A-Z]/', $node->nodeName)) {
                throw new RuntimeException(
                    "Component '{$node->nodeName}' not found. Make sure it's properly registered."
                );
            }

            $children = self::processChildNodes($node->childNodes);
            $attrs = self::getNodeAttributes($node) + ['children' => $children];

            return self::renderAsHtml($node->nodeName, $attrs);
        } finally {
            if ($pushed) {
                array_pop(self::$sectionStack);
                array_pop(self::$contextStack);
            }
        }
    }

    private static function getCurrentContext(): string
    {
        return end(self::$contextStack) ?: 'app';
    }


    private static function wrapHtmlWithOwnerTemplate(string $html, string $owner): string
    {
        $trimmed = trim($html);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (str_starts_with($trimmed, '<template')) {
            $openingTagEnd = self::findHtmlTagEnd($trimmed, 0);

            if ($openingTagEnd !== null) {
                $openingTag = substr($trimmed, 0, $openingTagEnd + 1);

                if (self::openingTagHasAttribute($openingTag, 'pp-owner')) {
                    return $trimmed;
                }
            }
        }

        $owner = htmlspecialchars($owner !== '' ? $owner : 'app', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<template pp-owner="' . $owner . '">' . "\n" . $trimmed . "\n" . '</template>';
    }

    private static function wrapElementWithOwnerTemplate(DOMElement $element, string $owner): void
    {
        $parent = $element->parentNode;

        if (!$parent instanceof DOMNode) {
            return;
        }

        $template = $element->ownerDocument->createElement('template');
        $template->setAttribute('pp-owner', $owner !== '' ? $owner : 'app');

        $parent->replaceChild($template, $element);
        $template->appendChild($element);
    }

    private static function processTextNode(DOMText $node): string
    {
        $parent = strtolower($node->parentNode?->nodeName ?? '');

        if (isset(self::LITERAL_TEXT_TAGS[$parent])) {
            return htmlspecialchars(
                $node->textContent,
                ENT_NOQUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        return $node->textContent;
    }

    private static function normalizePropsForComponent(array $props): array
    {
        $normalized = [];

        foreach ($props as $key => $value) {
            $camelKey = str_replace('-', '', lcfirst(ucwords($key, '-')));
            $normalized[$camelKey] = $value;

            if ($key !== $camelKey) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    protected static function renderComponent(
        DOMElement $node,
        string $componentName,
        array $incomingProps
    ): string {
        $mapping = self::selectComponentMapping($componentName);

        self::ensureClassLoaded($mapping['className'], $mapping['filePath']);
        self::validateComponentChildren($mapping['className'], $node);

        $sectionId = self::generateSectionId($mapping['className']);
        $normalizedProps = self::normalizePropsForComponent($incomingProps);

        $originalStack = self::$sectionStack;
        $originalContextStack = self::$contextStack;

        $parentContext = self::getCurrentContext();

        $componentFilePath = SRC_PATH . '/' . str_replace('\\', '/', $mapping['filePath']);
        $componentFilePath = str_replace('\\', '/', $componentFilePath);

        self::$sectionStack[] = $sectionId;
        self::$contextStack[] = $sectionId;
        self::$componentFileStack[] = $componentFilePath;

        try {
            $instance = self::initializeComponentInstance($mapping, $normalizedProps);

            $reflection = self::getClassReflection($mapping['className']);

            if ($reflection['hasPublicChildren']) {
                $instance->children = self::getChildrenWithContextInheritance(
                    $node,
                    $parentContext,
                    $sectionId
                );
            }

            return self::compileComponentHtml(
                $instance->render(),
                $sectionId,
                $incomingProps,
                $parentContext
            );
        } finally {
            self::$sectionStack = $originalStack;
            self::$contextStack = $originalContextStack;
            array_pop(self::$componentFileStack);
        }
    }

    private static function validateComponentChildren(
        string $className,
        DOMElement $node
    ): void {
        $hasChildren = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $hasChildren = true;
                break;
            }
            if ($child instanceof DOMText && trim($child->textContent) !== '') {
                $hasChildren = true;
                break;
            }
        }

        if (!$hasChildren) {
            return;
        }

        $reflection = self::getClassReflection($className);

        if (!$reflection['hasPublicChildren']) {
            throw new ComponentValidationException(
                'children',
                $className,
                $reflection['propertyNames']
            );
        }
    }

    private static function getChildrenWithContextInheritance(
        DOMElement $node,
        string $parentContext,
        string $componentId
    ): string {
        $originalContextStack = self::$contextStack;

        self::$contextStack = [$parentContext];

        try {
            $childrenHtml = '';
            $hasChildren = false;

            foreach ($node->childNodes as $child) {
                if (!$hasChildren && (
                    $child instanceof DOMElement ||
                    ($child instanceof DOMText && trim($child->textContent) !== '')
                )) {
                    $hasChildren = true;
                }

                $childrenHtml .= self::processNode($child);
            }

            $childrenHtml = trim($childrenHtml);

            if (!$hasChildren) {
                return $childrenHtml;
            }

            return self::wrapHtmlWithOwnerTemplate($childrenHtml, $parentContext);
        } finally {
            self::$contextStack = $originalContextStack;
        }
    }

    private static function generateSectionId(string $className): string
    {
        $baseId = 's' . base_convert(sprintf('%u', crc32($className)), 10, 36);
        $idx = self::$componentInstanceCounts[$baseId] ?? 0;
        self::$componentInstanceCounts[$baseId] = $idx + 1;

        return $idx === 0 ? $baseId : "{$baseId}{$idx}";
    }

    private static function compileComponentHtml(
        string $html,
        string $sectionId,
        array $incomingProps = [],
        string $parentContext = ''
    ): string {
        $html = self::preprocessFragmentSyntax($html);
        $normalizeDynamicAttributes = self::mightHaveDynamicCamelAttributes($html);

        $normalizedProps = self::normalizeComponentProps($incomingProps, !empty($parentContext));
        $regularProps = $normalizedProps['regularProps'];
        $eventListeners = $normalizedProps['eventListeners'];

        if ($parentContext === '') {
            $stringFastPathHtml = self::tryCompileSimpleRootComponentHtmlWithoutDom(
                $html,
                $sectionId,
                $regularProps,
                $eventListeners,
                $normalizeDynamicAttributes
            );

            if ($stringFastPathHtml !== null) {
                return $stringFastPathHtml;
            }
        }

        $fragDom = self::convertToXml($html);
        $rootElement = self::getSingleFragmentRootElement($fragDom);

        if ($rootElement !== null && $parentContext === '') {
            $fastPathHtml = self::tryCompileSimpleRootComponentHtml(
                $fragDom,
                $rootElement,
                $sectionId,
                $regularProps,
                $eventListeners,
                $normalizeDynamicAttributes
            );

            if ($fastPathHtml !== null) {
                return $fastPathHtml;
            }
        }

        $hasRegularProps = $regularProps !== [];
        $hasEventListeners = $eventListeners !== [];
        $relevantAttributes = ($hasRegularProps || $hasEventListeners)
            ? $normalizedProps['relevantAttributes']
            : [];
        $scopedEventAttributes = ($hasEventListeners && !empty($parentContext))
            ? $normalizedProps['scopedEventAttributes']
            : [];
        $analysisNode = $rootElement ?? $fragDom->documentElement;
        [
            'existingAttributes' => $existingAttributes,
            'eventElementsToWrap' => $eventElementsToWrap,
        ] = ($relevantAttributes !== [] || $scopedEventAttributes !== [])
            ? self::analyzeComponentTree(
                $analysisNode,
                $relevantAttributes,
                $scopedEventAttributes,
                $normalizeDynamicAttributes
            )
            : ['existingAttributes' => [], 'eventElementsToWrap' => []];

        $componentRoot = $rootElement;
        if ($componentRoot === null) {
            foreach ($fragDom->documentElement->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $componentRoot = $child;
                    break;
                }
            }
        }

        $needsScope = false;
        if ($componentRoot instanceof DOMElement) {
            $rootElement = $componentRoot;
            $componentRoot->setAttribute(self::COMPONENT_ATTRIBUTE, $sectionId);

            foreach ($regularProps as $propInfo) {
                $propName = $propInfo['rawName'];
                $kebabName = $propInfo['kebabName'];

                if (
                    isset($existingAttributes[$propName]) ||
                    isset($existingAttributes[$kebabName])
                ) {
                    continue;
                }

                $componentRoot->setAttribute($propInfo['htmlName'], (string) $propInfo['value']);
            }

            if (!empty($parentContext) && ($hasRegularProps || $hasEventListeners)) {
                $needsScope = true;
            }

            if ($hasEventListeners) {
                foreach ($eventListeners as $eventInfo) {
                    $eventName = $eventInfo['rawName'];
                    $kebabEventName = $eventInfo['kebabName'];

                    if (
                        isset($existingAttributes[$eventName]) ||
                        isset($existingAttributes[$kebabEventName])
                    ) {
                        continue;
                    }

                    if ($eventInfo['containsMustache']) {
                        $componentRoot->setAttribute($kebabEventName, (string) $eventInfo['value']);
                    } else {
                        $componentRoot->setAttribute($eventName, (string) $eventInfo['value']);
                    }
                }
            }
        }

        if ($eventElementsToWrap !== []) {
            foreach ($eventElementsToWrap as $elementToWrap) {
                self::wrapElementWithOwnerTemplate($elementToWrap, $parentContext);
            }
        }

        $htmlOut = self::innerXml($fragDom);

        if ($needsScope && $rootElement && !empty($parentContext)) {
            $htmlOut = self::wrapHtmlWithOwnerTemplate($htmlOut, $parentContext);
        }

        $htmlOut = self::normalizeSelfClosingTags($htmlOut);

        if (self::needsRecompilation($htmlOut)) {
            $htmlOut = self::compile($htmlOut);
        }

        return $htmlOut;
    }

    /**
     * @param array<string, array{rawName:string, kebabName:string, htmlName:string, value:mixed, containsMustache:bool}> $regularProps
     * @param array<string, array{rawName:string, kebabName:string, htmlName:string, value:mixed, containsMustache:bool}> $eventListeners
     */
    private static function tryCompileSimpleRootComponentHtmlWithoutDom(
        string $html,
        string $sectionId,
        array $regularProps,
        array $eventListeners,
        bool $normalizeDynamicAttributes
    ): ?string {
        $analysis = self::analyzeSingleRootHtml($html);
        if ($analysis === null) {
            return null;
        }

        if (!$analysis['selfClosing']) {
            $closingTagStart = strripos(
                substr($analysis['trimmedHtml'], 0, $analysis['rootEnd'] + 1),
                '</' . $analysis['tagName']
            );

            if ($closingTagStart === false) {
                return null;
            }

            $innerHtml = substr(
                $analysis['trimmedHtml'],
                strlen($analysis['openingTag']),
                $closingTagStart - strlen($analysis['openingTag'])
            );

            if (str_contains($innerHtml, '<')) {
                return null;
            }
        }

        $normalizedOpeningTag = self::normalizeOpeningTagForStringFastPath(
            $analysis['openingTag'],
            $normalizeDynamicAttributes,
            $analysis['selfClosing']
        );

        if ($normalizedOpeningTag === null) {
            return null;
        }

        $openingTag = $normalizedOpeningTag['openingTag'];
        $existingAttributes = $normalizedOpeningTag['existingAttributes'];
        $attributesToAppend = [];

        if (!isset($existingAttributes[self::COMPONENT_ATTRIBUTE])) {
            $attributesToAppend[self::COMPONENT_ATTRIBUTE] = $sectionId;
            $existingAttributes[self::COMPONENT_ATTRIBUTE] = true;
        }

        foreach ($regularProps as $propInfo) {
            $propName = $propInfo['rawName'];
            $kebabName = $propInfo['kebabName'];

            if (isset($existingAttributes[$propName]) || isset($existingAttributes[$kebabName])) {
                continue;
            }

            $attributesToAppend[$propInfo['htmlName']] = (string) $propInfo['value'];
            $existingAttributes[$propName] = true;
            $existingAttributes[$kebabName] = true;
        }

        foreach ($eventListeners as $eventInfo) {
            $eventName = $eventInfo['rawName'];
            $kebabEventName = $eventInfo['kebabName'];

            if (isset($existingAttributes[$eventName]) || isset($existingAttributes[$kebabEventName])) {
                continue;
            }

            $attributesToAppend[$eventInfo['containsMustache'] ? $kebabEventName : $eventName]
                = (string) $eventInfo['value'];
            $existingAttributes[$eventName] = true;
            $existingAttributes[$kebabEventName] = true;
        }

        if ($attributesToAppend !== []) {
            $insertPosition = self::getOpeningTagAttributeInsertPosition($openingTag, strlen($openingTag) - 1);
            $openingTag = substr($openingTag, 0, $insertPosition)
                . self::buildStringFastPathAttributeMarkup($attributesToAppend)
                . substr($openingTag, $insertPosition);
        }

        $htmlOut = $analysis['leadingWhitespace']
            . $openingTag
            . substr($analysis['trimmedHtml'], strlen($analysis['openingTag']))
            . $analysis['trailingWhitespace'];

        $htmlOut = self::normalizeSelfClosingTags($htmlOut);

        if (self::needsRecompilation($htmlOut)) {
            $htmlOut = self::compile($htmlOut);
        }

        return $htmlOut;
    }

    /**
     * @param array<string, array{rawName:string, kebabName:string, htmlName:string, value:mixed, containsMustache:bool}> $regularProps
     * @param array<string, array{rawName:string, kebabName:string, htmlName:string, value:mixed, containsMustache:bool}> $eventListeners
     */
    private static function tryCompileSimpleRootComponentHtml(
        DOMDocument $fragDom,
        DOMElement $rootElement,
        string $sectionId,
        array $regularProps,
        array $eventListeners,
        bool $normalizeDynamicAttributes
    ): ?string {
        if (self::rootElementHasChildElements($rootElement)) {
            return null;
        }

        $existingAttributes = self::normalizeElementAttributesForFastPath($rootElement, $normalizeDynamicAttributes);
        $rootElement->setAttribute(self::COMPONENT_ATTRIBUTE, $sectionId);

        foreach ($regularProps as $propInfo) {
            $propName = $propInfo['rawName'];
            $kebabName = $propInfo['kebabName'];

            if (
                isset($existingAttributes[$propName]) ||
                isset($existingAttributes[$kebabName])
            ) {
                continue;
            }

            $rootElement->setAttribute($propInfo['htmlName'], (string) $propInfo['value']);
        }

        foreach ($eventListeners as $eventInfo) {
            $eventName = $eventInfo['rawName'];
            $kebabEventName = $eventInfo['kebabName'];

            if (
                isset($existingAttributes[$eventName]) ||
                isset($existingAttributes[$kebabEventName])
            ) {
                continue;
            }

            $rootElement->setAttribute(
                $eventInfo['containsMustache'] ? $kebabEventName : $eventName,
                (string) $eventInfo['value']
            );
        }

        $htmlOut = self::innerXml($fragDom);
        $htmlOut = self::normalizeSelfClosingTags($htmlOut);

        if (self::needsRecompilation($htmlOut)) {
            $htmlOut = self::compile($htmlOut);
        }

        return $htmlOut;
    }

    private static function getSingleFragmentRootElement(DOMDocument $fragDom): ?DOMElement
    {
        $rootElement = null;

        foreach ($fragDom->documentElement->childNodes as $child) {
            if ($child instanceof DOMComment) {
                continue;
            }

            if ($child instanceof DOMText) {
                if (trim($child->textContent) === '') {
                    continue;
                }

                return null;
            }

            if (!$child instanceof DOMElement) {
                return null;
            }

            if ($rootElement !== null) {
                return null;
            }

            $rootElement = $child;
        }

        return $rootElement;
    }

    /**
     * @return array{openingTag:string, existingAttributes: array<string, true>}|null
     */
    private static function normalizeOpeningTagForStringFastPath(
        string $openingTag,
        bool $normalizeDynamicAttributes,
        bool $selfClosing
    ): ?array {
        if (preg_match('/\A<([A-Za-z][\w:-]*)\b([\s\S]*?)>\z/s', $openingTag, $matches) !== 1) {
            return null;
        }

        $tagName = $matches[1];
        $attributeMarkup = $matches[2];

        if ($selfClosing) {
            $attributeMarkup = preg_replace('/\/\s*\z/', '', $attributeMarkup) ?? $attributeMarkup;
        }

        $attributeMarkup = trim($attributeMarkup);
        $existingAttributes = [];

        if ($attributeMarkup === '') {
            return [
                'openingTag' => $selfClosing ? '<' . $tagName . ' />' : '<' . $tagName . '>',
                'existingAttributes' => $existingAttributes,
            ];
        }

        preg_match_all(
            "/([\w:-]+)\s*(?:=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s>]+)))?/i",
            $attributeMarkup,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            return null;
        }

        $normalizedAttributes = [];

        foreach ($matches as $match) {
            $originalName = $match[1];
            $hasExplicitValue = str_contains($match[0], '=');

            if (isset($match[2]) && $match[2] !== '') {
                $value = $match[2];
            } elseif (isset($match[3]) && $match[3] !== '') {
                $value = $match[3];
            } elseif (isset($match[4]) && $match[4] !== '') {
                $value = $match[4];
            } else {
                $value = '';
            }

            $normalizedName = $originalName;
            if ($normalizeDynamicAttributes && self::containsMustacheSyntax($value)) {
                $normalizedName = self::camelToKebab($originalName);
            }

            $existingAttributes[$originalName] = true;
            $existingAttributes[$normalizedName] = true;
            $normalizedAttributes[] = [
                'name' => $normalizedName,
                'value' => $value,
                'hasExplicitValue' => $hasExplicitValue,
            ];
        }

        $rebuiltOpeningTag = '<' . $tagName;

        foreach ($normalizedAttributes as $attribute) {
            $rebuiltOpeningTag .= ' ' . $attribute['name'];

            if ($attribute['hasExplicitValue']) {
                $rebuiltOpeningTag .= '="'
                    . htmlspecialchars($attribute['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '"';
            }
        }

        $rebuiltOpeningTag .= $selfClosing ? ' />' : '>';

        return [
            'openingTag' => $rebuiltOpeningTag,
            'existingAttributes' => $existingAttributes,
        ];
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function buildStringFastPathAttributeMarkup(array $attributes): string
    {
        $markup = '';

        foreach ($attributes as $name => $value) {
            $markup .= ' ' . $name . '="'
                . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"';
        }

        return $markup;
    }

    private static function rootElementHasChildElements(DOMElement $rootElement): bool
    {
        foreach ($rootElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function normalizeElementAttributesForFastPath(
        DOMElement $element,
        bool $normalizeDynamicAttributes
    ): array {
        $existingAttributes = [];
        $attributesToRename = [];

        foreach ($element->attributes as $attribute) {
            $attributeName = $attribute->name;
            $attributeValue = $attribute->value;

            if (
                $normalizeDynamicAttributes &&
                self::attributeNeedsDynamicNormalization($attributeName, $attributeValue)
            ) {
                $kebabName = self::camelToKebab($attributeName);

                if ($kebabName !== $attributeName) {
                    $attributesToRename[$attributeName] = [
                        'kebabName' => $kebabName,
                        'value' => $attributeValue,
                    ];
                    $attributeName = $kebabName;
                }
            }

            $existingAttributes[$attributeName] = true;
        }

        foreach ($attributesToRename as $oldName => $renameInfo) {
            $element->removeAttribute($oldName);
            $element->setAttribute($renameInfo['kebabName'], $renameInfo['value']);
        }

        return $existingAttributes;
    }

    /**
     * @param array<string, true> $targetAttributes
     * @param array<string, array<string, true>> $scopedEventAttributes
     * @return array{existingAttributes: array<string, true>, eventElementsToWrap: list<DOMElement>}
     */
    private static function analyzeComponentTree(
        ?DOMNode $node,
        array $targetAttributes,
        array $scopedEventAttributes,
        bool $normalizeDynamicAttributes
    ): array {
        $foundAttributes = [];
        $eventElementsToWrap = [];

        if ($node instanceof DOMElement) {
            self::collectComponentTreeState(
                $node,
                $targetAttributes,
                $scopedEventAttributes,
                $normalizeDynamicAttributes,
                $foundAttributes,
                $eventElementsToWrap
            );
        }

        return [
            'existingAttributes' => $foundAttributes,
            'eventElementsToWrap' => $eventElementsToWrap,
        ];
    }

    /**
     * @param array<string, true> $targetAttributes
     * @param array<string, array<string, true>> $scopedEventAttributes
     * @param array<string, true> $foundAttributes
     * @param list<DOMElement> $eventElementsToWrap
     */
    private static function collectComponentTreeState(
        DOMElement $node,
        array $targetAttributes,
        array $scopedEventAttributes,
        bool $normalizeDynamicAttributes,
        array &$foundAttributes,
        array &$eventElementsToWrap,
        bool $insideOwnerTemplate = false
    ): void {
        $allowEventWrapping = !$insideOwnerTemplate;

        $matchesScopedEventListener = false;
        $attributesToRename = [];

        foreach ($node->attributes as $attribute) {
            $attributeName = $attribute->name;
            $attributeValue = $attribute->value;

            if (
                $normalizeDynamicAttributes &&
                self::attributeNeedsDynamicNormalization($attributeName, $attributeValue)
            ) {
                $kebabName = self::camelToKebab($attributeName);

                if ($kebabName !== $attributeName) {
                    $attributesToRename[$attributeName] = [
                        'kebabName' => $kebabName,
                        'value' => $attributeValue,
                    ];
                    $attributeName = $kebabName;
                }
            }

            if (isset($targetAttributes[$attributeName])) {
                $foundAttributes[$attributeName] = true;
            }

            if (
                $allowEventWrapping &&
                !$matchesScopedEventListener &&
                isset($scopedEventAttributes[$attributeName][$attributeValue])
            ) {
                $matchesScopedEventListener = true;
            }
        }

        foreach ($attributesToRename as $oldName => $renameInfo) {
            $node->removeAttribute($oldName);
            $node->setAttribute($renameInfo['kebabName'], $renameInfo['value']);
        }

        if ($matchesScopedEventListener) {
            $eventElementsToWrap[] = $node;
            $allowEventWrapping = false;
        }

        $childInsideOwnerTemplate = $insideOwnerTemplate || self::isOwnerTemplateElement($node);

        for ($child = $node->firstChild; $child !== null; $child = $nextSibling) {
            $nextSibling = $child->nextSibling;

            if (!$child instanceof DOMElement) {
                continue;
            }

            self::collectComponentTreeState(
                $child,
                $targetAttributes,
                $scopedEventAttributes,
                $normalizeDynamicAttributes,
                $foundAttributes,
                $eventElementsToWrap,
                $childInsideOwnerTemplate
            );
        }
    }

    private static function isOwnerTemplateElement(DOMNode $node): bool
    {
        if (!$node instanceof DOMElement || !$node->hasAttribute('pp-owner')) {
            return false;
        }

        return $node->tagName === 'template' || strcasecmp($node->tagName, 'template') === 0;
    }

    private static function attributeNeedsDynamicNormalization(
        string $attributeName,
        string $attributeValue
    ): bool {
        return strpbrk($attributeName, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false
            && str_contains($attributeValue, '{')
            && str_contains($attributeValue, '}');
    }

    private static function mightHaveDynamicCamelAttributes(string $html): bool
    {
        return str_contains($html, '{')
            && preg_match('/\s[a-z][\w:-]*[A-Z][\w:-]*\s*=/', $html) === 1;
    }

    /**
     * Checks if a string contains mustache syntax (curly braces).
     *
     * @param mixed $value The value to check.
     * @return bool True if the value contains mustache syntax, false otherwise.
     */
    public static function containsMustacheSyntax(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return str_contains($value, '{') && str_contains($value, '}');
    }

    /**
     * @param array<string, mixed> $props
     * @return array{
     *   regularProps: array<string, array{rawName:string, kebabName:string, htmlName:string, value:mixed, containsMustache:bool}>,
     *   eventListeners: array<string, array{rawName:string, kebabName:string, htmlName:string, value:mixed, containsMustache:bool}>,
     *   relevantAttributes: array<string, true>,
     *   scopedEventAttributes: array<string, array<string, true>>
     * }
     */
    private static function normalizeComponentProps(array $props, bool $buildScopedEventAttributes = false): array
    {
        $regularProps = [];
        $eventListeners = [];
        $relevantAttributes = [];
        $scopedEventAttributes = [];

        foreach ($props as $key => $value) {
            $metadata = self::getComponentPropMetadata((string) $key);
            $containsMustache = self::containsMustacheSyntax($value);

            if ($metadata['isEvent']) {
                $eventListeners[$key] = [
                    'rawName' => $metadata['rawName'],
                    'kebabName' => $metadata['kebabName'],
                    'htmlName' => $containsMustache ? $metadata['kebabName'] : $metadata['rawName'],
                    'value' => $value,
                    'containsMustache' => $containsMustache,
                ];
                $relevantAttributes[$metadata['rawName']] = true;
                $relevantAttributes[$metadata['kebabName']] = true;

                if ($buildScopedEventAttributes) {
                    $handlerValue = (string) $value;
                    $scopedEventAttributes[$metadata['rawName']][$handlerValue] = true;
                    $scopedEventAttributes[$metadata['kebabName']][$handlerValue] = true;
                }

                continue;
            }

            if (!$metadata['isSystem']) {
                $regularProps[$key] = [
                    'rawName' => $metadata['rawName'],
                    'kebabName' => $metadata['kebabName'],
                    'htmlName' => $containsMustache ? $metadata['kebabName'] : $metadata['rawName'],
                    'value' => $value,
                    'containsMustache' => $containsMustache,
                ];
                $relevantAttributes[$metadata['rawName']] = true;
                $relevantAttributes[$metadata['kebabName']] = true;
            }
        }

        return [
            'regularProps' => $regularProps,
            'eventListeners' => $eventListeners,
            'relevantAttributes' => $relevantAttributes,
            'scopedEventAttributes' => $scopedEventAttributes,
        ];
    }

    /**
     * @return array{rawName:string, kebabName:string, isEvent:bool, isSystem:bool}
     */
    private static function getComponentPropMetadata(string $key): array
    {
        if (isset(self::$componentPropMetadataCache[$key])) {
            return self::$componentPropMetadataCache[$key];
        }

        $metadata = [
            'rawName' => $key,
            'kebabName' => self::camelToKebab($key),
            'isEvent' => strlen($key) > 2 && str_starts_with(strtolower($key), 'on'),
            'isSystem' => isset(self::SYSTEM_PROPS[$key]),
        ];

        self::$componentPropMetadataCache[$key] = $metadata;

        return $metadata;
    }

    private static function needsRecompilation(string $html): bool
    {
        if (stripos($html, '<script') !== false) {
            return true;
        }

        return strpbrk($html, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false
            && preg_match(self::COMPONENT_TAG_REGEX, $html) === 1;
    }

    private static function normalizeSelfClosingTags(string $html): string
    {
        if (!str_contains($html, '/>')) {
            return $html;
        }

        return preg_replace_callback(
            self::SELF_CLOSING_REGEX,
            static fn($m) => isset(self::SELF_CLOSING_TAGS[strtolower($m[1])])
                ? $m[0]
                : "<{$m[1]}{$m[2]}></{$m[1]}>",
            $html
        );
    }

    private static function initializeComponentInstance(array $mapping, array $attributes)
    {
        ['className' => $className, 'filePath' => $filePath] = $mapping;

        self::ensureClassLoaded($className, $filePath);
        $reflection = self::getClassReflection($className);

        self::validateComponentProps($className, $attributes);

        $instance = $reflection['class']->newInstance($attributes);

        return $instance;
    }

    private static function ensureClassLoaded(string $className, string $filePath): void
    {
        if (!class_exists($className)) {
            require_once str_replace('\\', '/', SRC_PATH . '/' . $filePath);

            if (!class_exists($className)) {
                throw new RuntimeException("Class {$className} not found");
            }
        }
    }

    private static function getClassReflection(string $className): array
    {
        if (!isset(self::$reflectionCache[$className])) {
            if (!class_exists($className, false)) {
                throw new RuntimeException(
                    "Cannot get reflection for class '{$className}' - class not loaded. " .
                        "This is likely a bug in the template compiler."
                );
            }

            $rc = new ReflectionClass($className);
            $publicProps = array_filter(
                $rc->getProperties(ReflectionProperty::IS_PUBLIC),
                static fn(ReflectionProperty $p) => !$p->isStatic()
            );
            $propertyNames = array_map(static fn(ReflectionProperty $p) => $p->getName(), $publicProps);
            $requiredProps = [];

            foreach ($publicProps as $property) {
                $type = $property->getType();

                if (
                    $type instanceof ReflectionNamedType &&
                    $type->isBuiltin() &&
                    !$type->allowsNull()
                ) {
                    $requiredProps[] = $property->getName();
                }
            }

            self::$reflectionCache[$className] = [
                'class' => $rc,
                'constructor' => $rc->getConstructor(),
                'properties' => $publicProps,
                'propertyNames' => $propertyNames,
                'requiredProps' => $requiredProps,
                'hasPublicChildren' => in_array('children', $propertyNames, true),
                'allowedProps' => self::SYSTEM_PROPS + array_flip($propertyNames),
            ];
        }

        return self::$reflectionCache[$className];
    }

    private static function validateComponentProps(string $className, array $attributes): void
    {
        $reflection = self::getClassReflection($className);

        foreach ($reflection['requiredProps'] as $name) {
            if (!array_key_exists($name, $attributes)) {
                throw new ComponentValidationException(
                    $name,
                    $className,
                    $reflection['propertyNames']
                );
            }
        }
    }

    private static function selectComponentMapping(string $componentName): array
    {
        if (!isset(self::$classMappings[$componentName])) {
            throw new RuntimeException("Component {$componentName} not registered");
        }

        $mappings = self::$classMappings[$componentName];

        if (!isset($mappings[0]) || !is_array($mappings[0])) {
            return $mappings;
        }

        $currentFile = self::normalizePathForComparison(Bootstrap::$contentToInclude);

        if (!empty(self::$componentFileStack)) {
            foreach ($mappings as $entry) {
                if (isset($entry['importer'])) {
                    $importerPath = self::normalizePathForComparison($entry['importer']);
                    foreach (self::$componentFileStack as $stackFile) {
                        $normalizedStackFile = self::normalizePathForComparison($stackFile);
                        if (
                            self::pathsMatch($importerPath, $normalizedStackFile) &&
                            self::componentNameMatchesClassName($componentName, $entry['className'])
                        ) {
                            return $entry;
                        }
                    }
                }
            }

            foreach ($mappings as $entry) {
                if (isset($entry['importer'])) {
                    $importerPath = self::normalizePathForComparison($entry['importer']);
                    foreach (self::$componentFileStack as $stackFile) {
                        $normalizedStackFile = self::normalizePathForComparison($stackFile);
                        if (self::pathsMatch($importerPath, $normalizedStackFile)) {
                            return $entry;
                        }
                    }
                }
            }
        }

        foreach ($mappings as $entry) {
            if (isset($entry['importer'])) {
                $importerPath = self::normalizePathForComparison($entry['importer']);
                if (
                    self::pathsMatch($importerPath, $currentFile) &&
                    self::componentNameMatchesClassName($componentName, $entry['className'])
                ) {
                    return $entry;
                }
            }
        }

        foreach ($mappings as $entry) {
            if (isset($entry['importer'])) {
                $importerPath = self::normalizePathForComparison($entry['importer']);
                if (self::pathsMatch($importerPath, $currentFile)) {
                    return $entry;
                }
            }
        }

        foreach ($mappings as $entry) {
            if (self::componentNameMatchesClassName($componentName, $entry['className'])) {
                return $entry;
            }
        }

        return $mappings[0];
    }

    private static function normalizePathForComparison(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $srcPath = str_replace('\\', '/', SRC_PATH);

        if (str_starts_with($path, $srcPath)) {
            $path = substr($path, strlen($srcPath));
            $path = ltrim($path, '/');
        }

        if (preg_match('#[a-zA-Z]:/.*?/src/(.+)$#', $path, $matches)) {
            $path = $matches[1];
        }

        if (preg_match('#/src/(.+)$#', $path, $matches)) {
            $path = $matches[1];
        }

        return strtolower(trim($path, '/'));
    }

    private static function pathsMatch(string $path1, string $path2): bool
    {
        if ($path1 === $path2) {
            return true;
        }

        $parts1 = array_filter(explode('/', $path1));
        $parts2 = array_filter(explode('/', $path2));

        if (count($parts1) === count($parts2)) {
            return $parts1 === $parts2;
        }

        $minCount = min(count($parts1), count($parts2));
        if ($minCount > 0) {
            $slice1 = array_slice($parts1, -$minCount);
            $slice2 = array_slice($parts2, -$minCount);
            return $slice1 === $slice2;
        }

        return false;
    }

    private static function componentNameMatchesClassName(string $componentName, string $className): bool
    {
        $parts = explode('\\', $className);
        $lastPart = end($parts);

        return $componentName === $lastPart;
    }

    public static function innerXml(DOMNode $node): string
    {
        if ($node instanceof DOMDocument) {
            $node = $node->documentElement;
        }

        if (!$node || !$node->hasChildNodes()) {
            return '';
        }

        $document = $node instanceof DOMDocument ? $node : $node->ownerDocument;
        $xml = '';

        foreach ($node->childNodes as $child) {
            $xml .= $document->saveXML($child);
        }

        return $xml;
    }

    private static function tryScopeRouteRootWithoutDom(string $htmlContent, string $componentId): ?string
    {
        $analysis = self::analyzeSingleRootHtml($htmlContent);

        if ($analysis === null) {
            return null;
        }

        $openingTag = $analysis['openingTag'];

        if (self::openingTagHasAttribute($openingTag, self::COMPONENT_ATTRIBUTE)) {
            return $htmlContent;
        }

        $trimmedHtml = $analysis['trimmedHtml'];
        $attributeMarkup = ' ' . self::COMPONENT_ATTRIBUTE . '="'
            . htmlspecialchars($componentId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '"';
        $insertPosition = self::getOpeningTagAttributeInsertPosition($trimmedHtml, $analysis['openingTagEnd']);
        $scopedHtml = substr($trimmedHtml, 0, $insertPosition)
            . $attributeMarkup
            . substr($trimmedHtml, $insertPosition);

        return $analysis['leadingWhitespace'] . $scopedHtml . $analysis['trailingWhitespace'];
    }

    /**
     * @return array{
     *   leadingWhitespace:string,
     *   trimmedHtml:string,
     *   trailingWhitespace:string,
     *   openingTag:string,
     *   tagName:string,
     *   openingTagEnd:int,
     *   rootEnd:int,
     *   selfClosing:bool
     * }|null
     */
    private static function analyzeSingleRootHtml(string $htmlContent): ?array
    {
        if (preg_match('/\A(\s*)(.*?)(\s*)\z/s', $htmlContent, $outerMatches) !== 1) {
            return null;
        }

        $trimmedHtml = $outerMatches[2];
        if ($trimmedHtml === '' || !str_starts_with($trimmedHtml, '<')) {
            return null;
        }

        if (str_starts_with($trimmedHtml, '<!--')) {
            return null;
        }

        $openingTagEnd = self::findHtmlTagEnd($trimmedHtml, 0);
        if ($openingTagEnd === null) {
            return null;
        }

        $openingTag = substr($trimmedHtml, 0, $openingTagEnd + 1);
        if (preg_match('/\A<([A-Za-z][\w:-]*)\b[\s\S]*>\z/s', $openingTag, $matches) !== 1) {
            return null;
        }

        $tagName = strtolower($matches[1]);
        if ($tagName === 'script' || $tagName === 'style') {
            return null;
        }

        $selfClosing = self::isSelfClosingOpeningTag($openingTag);

        if ($selfClosing) {
            if (!self::hasOnlyWhitespaceAndCommentsAfter($trimmedHtml, $openingTagEnd + 1)) {
                return null;
            }

            return [
                'leadingWhitespace' => $outerMatches[1],
                'trimmedHtml' => $trimmedHtml,
                'trailingWhitespace' => $outerMatches[3],
                'openingTag' => $openingTag,
                'tagName' => $tagName,
                'openingTagEnd' => $openingTagEnd,
                'rootEnd' => $openingTagEnd,
                'selfClosing' => true,
            ];
        }

        $depth = 1;
        $cursor = $openingTagEnd + 1;

        while ($depth > 0) {
            $nextTagPos = strpos($trimmedHtml, '<', $cursor);
            if ($nextTagPos === false) {
                return null;
            }

            if (substr($trimmedHtml, $nextTagPos, 4) === '<!--') {
                $commentEnd = strpos($trimmedHtml, '-->', $nextTagPos + 4);
                if ($commentEnd === false) {
                    return null;
                }

                $cursor = $commentEnd + 3;
                continue;
            }

            if (substr($trimmedHtml, $nextTagPos, 9) === '<![CDATA[') {
                $cdataEnd = strpos($trimmedHtml, ']]>', $nextTagPos + 9);
                if ($cdataEnd === false) {
                    return null;
                }

                $cursor = $cdataEnd + 3;
                continue;
            }

            if (substr($trimmedHtml, $nextTagPos, 2) === '<?') {
                $processingInstructionEnd = strpos($trimmedHtml, '?>', $nextTagPos + 2);
                if ($processingInstructionEnd === false) {
                    return null;
                }

                $cursor = $processingInstructionEnd + 2;
                continue;
            }

            if (str_starts_with(substr($trimmedHtml, $nextTagPos), '<!DOCTYPE')) {
                return null;
            }

            $tagEnd = self::findHtmlTagEnd($trimmedHtml, $nextTagPos);
            if ($tagEnd === null) {
                return null;
            }

            $tagMarkup = substr($trimmedHtml, $nextTagPos, $tagEnd - $nextTagPos + 1);

            if (preg_match('/\A<\/([A-Za-z][\w:-]*)\b[^>]*>\z/s', $tagMarkup, $tagMatch) === 1) {
                if (strtolower($tagMatch[1]) === $tagName) {
                    $depth--;
                }

                $cursor = $tagEnd + 1;
                continue;
            }

            if (preg_match('/\A<([A-Za-z][\w:-]*)\b[\s\S]*>\z/s', $tagMarkup, $tagMatch) !== 1) {
                return null;
            }

            $currentTagName = strtolower($tagMatch[1]);
            $currentSelfClosing = self::isSelfClosingOpeningTag($tagMarkup);

            if (($currentTagName === 'script' || $currentTagName === 'style') && !$currentSelfClosing) {
                $closingTagPos = stripos($trimmedHtml, '</' . $currentTagName, $tagEnd + 1);
                if ($closingTagPos === false) {
                    return null;
                }

                $closingTagEnd = self::findHtmlTagEnd($trimmedHtml, $closingTagPos);
                if ($closingTagEnd === null) {
                    return null;
                }

                $cursor = $closingTagEnd + 1;
                continue;
            }

            if ($currentTagName === $tagName && !$currentSelfClosing) {
                $depth++;
            }

            $cursor = $tagEnd + 1;
        }

        if (!self::hasOnlyWhitespaceAndCommentsAfter($trimmedHtml, $cursor)) {
            return null;
        }

        return [
            'leadingWhitespace' => $outerMatches[1],
            'trimmedHtml' => $trimmedHtml,
            'trailingWhitespace' => $outerMatches[3],
            'openingTag' => $openingTag,
            'tagName' => $tagName,
            'openingTagEnd' => $openingTagEnd,
            'rootEnd' => $cursor - 1,
            'selfClosing' => false,
        ];
    }

    private static function findHtmlTagEnd(string $html, int $start): ?int
    {
        $length = strlen($html);
        $quote = null;

        for ($index = $start + 1; $index < $length; $index++) {
            $char = $html[$index];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '>') {
                return $index;
            }
        }

        return null;
    }

    private static function isSelfClosingOpeningTag(string $tagMarkup): bool
    {
        $index = strlen($tagMarkup) - 2;

        while ($index >= 0 && ctype_space($tagMarkup[$index])) {
            $index--;
        }

        return $index >= 0 && $tagMarkup[$index] === '/';
    }

    private static function hasOnlyWhitespaceAndCommentsAfter(string $html, int $offset): bool
    {
        $remainder = substr($html, $offset);
        if ($remainder === '') {
            return true;
        }

        $remainderWithoutComments = preg_replace('/<!--([\s\S]*?)-->/', '', $remainder);

        return trim($remainderWithoutComments ?? '') === '';
    }

    private static function openingTagHasAttribute(string $openingTag, string $attributeName): bool
    {
        return preg_match('/\b' . preg_quote($attributeName, '/') . '\s*=\s*/i', $openingTag) === 1;
    }

    private static function getOpeningTagAttributeInsertPosition(string $html, int $openingTagEnd): int
    {
        $insertPosition = $openingTagEnd;

        while ($insertPosition > 0 && ctype_space($html[$insertPosition - 1])) {
            $insertPosition--;
        }

        if ($insertPosition > 0 && $html[$insertPosition - 1] === '/') {
            return $insertPosition - 1;
        }

        return $insertPosition;
    }

    private static function createDomForSingleRootValidation(
        string $htmlContent,
        string $filePath,
        string $contextLabel
    ): DOMDocument {
        try {
            return self::convertToXml($htmlContent);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                sprintf(
                    '%s must render valid markup before single-root validation. File: %s. %s',
                    $contextLabel,
                    $filePath,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    private static function getSingleRootElementForValidation(
        DOMDocument $dom,
        string $filePath,
        string $contextLabel
    ): DOMElement {
        $wrapper = $dom->documentElement;

        if (!$wrapper) {
            throw new RuntimeException(
                sprintf(
                    '%s missing XML wrapper during single-root validation. File: %s',
                    $contextLabel,
                    $filePath
                )
            );
        }

        $nodes = [];

        foreach ($wrapper->childNodes as $node) {
            if ($node instanceof DOMComment) {
                continue;
            }

            if ($node instanceof DOMText && trim($node->textContent) === '') {
                continue;
            }

            $nodes[] = $node;
        }

        if (count($nodes) !== 1 || !$nodes[0] instanceof DOMElement) {
            throw new RuntimeException(
                sprintf(
                    '%s must render exactly one top-level HTML element. File: %s. Found: %s',
                    $contextLabel,
                    $filePath,
                    self::describeNodes($nodes)
                )
            );
        }

        return $nodes[0];
    }

    /**
     * @param list<DOMNode> $nodes
     */
    private static function describeNodes(array $nodes): string
    {
        if ($nodes === []) {
            return 'none';
        }

        $descriptions = array_map(
            static function (DOMNode $node): string {
                if ($node instanceof DOMComment) {
                    return 'comment';
                }

                if ($node instanceof DOMText) {
                    $text = trim($node->textContent);

                    if ($text === '') {
                        return 'text';
                    }

                    return 'text("' . $text . '")';
                }

                return $node->nodeName;
            },
            $nodes
        );

        return implode(', ', $descriptions);
    }

    private static function routeComponentIdFromPath(string $filePath): string
    {
        $normalizedPath = strtolower(str_replace('\\', '/', $filePath));
        $prefix = str_ends_with($normalizedPath, '/layout.php') ? 'layout_' : 'page_';

        return $prefix . base_convert(sprintf('%u', crc32($normalizedPath)), 10, 36);
    }

    private static function preprocessFragmentSyntax(string $content): string
    {
        if (!str_contains($content, '<>') && !str_contains($content, '</>')) {
            return $content;
        }

        return str_replace(['<>', '</>'], ['<Fragment>', '</Fragment>'], $content);
    }

    private static function getNodeAttributes(DOMElement $node): array
    {
        $attrs = [];
        foreach ($node->attributes as $attr) {
            $attrs[$attr->name] = $attr->value;
        }
        return $attrs;
    }

    private static function renderAsHtml(string $tag, array $attrs): string
    {
        $children = $attrs['children'] ?? '';
        unset($attrs['children']);

        $isComponent = isset($attrs[self::COMPONENT_ATTRIBUTE]);

        if (empty($attrs)) {
            $attrStr = '';
        } else {
            $pairs = [];
            foreach ($attrs as $name => $value) {
                if ($value === '' && !in_array($name, ['value', 'class'], true)) {
                    continue;
                }

                $htmlAttrName = $isComponent && self::containsMustacheSyntax($value)
                    ? self::camelToKebab($name)
                    : $name;

                $pairs[] = sprintf(
                    '%s="%s"',
                    $htmlAttrName,
                    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }
            $attrStr = empty($pairs) ? '' : ' ' . implode(' ', $pairs);
        }

        return isset(self::SELF_CLOSING_TAGS[strtolower($tag)])
            ? "<{$tag}{$attrStr} />"
            : "<{$tag}{$attrStr}>{$children}</{$tag}>";
    }

    /**
     * Converts camelCase string to kebab-case.
     *
     * @param string $string The camelCase string to convert.
     * @param array $systemProps Optional array of system properties to exclude from conversion.
     * @return string The converted kebab-case string.
     */
    public static function camelToKebab(string $string, array $systemProps = []): string
    {
        $originalString = $string;

        if ($systemProps === [] && isset(self::$camelToKebabCache[$string])) {
            return self::$camelToKebabCache[$string];
        }

        $systemProps = $systemProps ?: self::SYSTEM_PROPS;

        if (isset($systemProps[$string]) || str_contains($string, '-')) {
            if ($systemProps === self::SYSTEM_PROPS) {
                self::$camelToKebabCache[$string] = $string;
            }

            return $string;
        }

        $string = preg_replace('/([a-z\d])([A-Z])/', '$1-$2', $string);
        $string = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $string);

        $converted = strtolower($string);

        if ($systemProps === self::SYSTEM_PROPS) {
            self::$camelToKebabCache[$originalString] = $converted;
        }

        return $converted;
    }

    /**
     * Gets the default system properties.
     *
     * @return array The system properties array.
     */
    public static function getSystemProps(): array
    {
        return self::SYSTEM_PROPS;
    }

    protected static function initializeClassMappings(): void
    {
        self::$classMappings = PrismaPHPSettings::$classLogFiles;
    }

    protected static function getXmlErrors(): array
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();

        return array_map(static function (LibXMLError $error): string {
            $type = match ($error->level) {
                LIBXML_ERR_WARNING => 'Warning',
                LIBXML_ERR_ERROR => 'Error',
                LIBXML_ERR_FATAL => 'Fatal',
                default => 'Unknown',
            };

            return sprintf(
                "[%s] Line %d, Col %d: %s",
                $type,
                $error->line,
                $error->column,
                trim($error->message)
            );
        }, $errors);
    }
}
