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
use DOMXPath;
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
            $leastUsed = array_search(
                min(array_column(self::$cacheStats, 'hits')),
                array_column(self::$cacheStats, 'hits')
            );
            unset(self::$compiledCache[$leastUsed]);
            unset(self::$cacheStats[$leastUsed]);
        }

        $compiled = self::compileInternal($templateContent);
        self::$compiledCache[$hash] = $compiled;
        self::$cacheStats[$hash] = ['hits' => 0, 'created' => time()];

        return $compiled;
    }

    private static function compileInternal(string $templateContent): string
    {
        if (self::$compileDepth === 0) {
            self::$componentInstanceCounts = [];
            self::$contextStack = ['app'];
        }
        self::$compileDepth++;

        try {
            if (empty(self::$classMappings)) {
                self::initializeClassMappings();
            }

            $dom = self::convertToXml($templateContent);
            $output = self::processChildNodes($dom->documentElement->childNodes);

            return implode('', $output);
        } finally {
            self::$compileDepth--;
        }
    }

    public static function injectDynamicContent(string $htmlContent): string
    {
        $replacements = [
            self::HEAD_PATTERNS['open'] => '$1' . MainLayout::outputMetadata(),
            self::HEAD_PATTERNS['close'] => MainLayout::outputHeadScripts() . '$1',
            self::BODY_PATTERNS['close'] => MainLayout::outputFooterScripts() . '$1',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $htmlContent = preg_replace($pattern, $replacement, $htmlContent, 1);
        }

        if (
            !isset($_SERVER['HTTP_X_PP_NAVIGATION']) &&
            !PrismaPHPSettings::$option->backendOnly
        ) {
            $htmlContent = preg_replace(
                self::BODY_PATTERNS['open'],
                '<body$1 style="opacity:0;pointer-events:none;user-select:none;transition:opacity .18s ease-out;">',
                $htmlContent,
                1
            );
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

        return preg_replace_callback(
            self::getPattern('mustache'),
            static function (array $matches): string {
                if (!str_contains($matches[1], '<') && !str_contains($matches[1], '>')) {
                    return $matches[0];
                }

                $expression = str_replace(['<', '>'], ['&lt;', '&gt;'], $matches[1]);
                return '{' . $expression . '}';
            },
            $content
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

    private static function processChildNodes($childNodes): array
    {
        $output = [];
        foreach ($childNodes as $child) {
            $output[] = self::processNode($child);
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
        if (!preg_match('/\s\w+=["\']/', $html)) {
            return $html;
        }

        return preg_replace_callback(
            self::getPattern('attribute'),
            static function (array $m): string {
                if (!str_contains($m[3], '<') && !str_contains($m[3], '>')) {
                    return $m[0];
                }

                return $m[1] . $m[2] .
                    str_replace(['<', '>'], ['&lt;', '&gt;'], $m[3]) . $m[2];
            },
            $html
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
            $content
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

            $children = implode('', self::processChildNodes($node->childNodes));
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

    protected static function renderComponent(
        DOMElement $node,
        string $componentName,
        array $incomingProps
    ): string {
        $mapping = self::selectComponentMapping($componentName);

        self::ensureClassLoaded($mapping['className'], $mapping['filePath']);
        self::validateComponentChildren($mapping['className'], $node);

        $sectionId = self::generateSectionId($mapping['className']);

        $originalStack = self::$sectionStack;
        $originalContextStack = self::$contextStack;

        $parentContext = self::getCurrentContext();

        self::$sectionStack[] = $sectionId;
        self::$contextStack[] = $sectionId;

        try {
            $instance = self::initializeComponentInstance($mapping, $incomingProps);

            $reflection = self::getClassReflection($mapping['className']);
            $hasPublicChildren = false;

            foreach ($reflection['properties'] as $prop) {
                if ($prop->getName() === 'children' && $prop->isPublic()) {
                    $hasPublicChildren = true;
                    break;
                }
            }

            if ($hasPublicChildren) {
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

        $hasChildrenProp = false;
        foreach ($reflection['properties'] as $prop) {
            if ($prop->getName() === 'children') {
                $hasChildrenProp = true;
                break;
            }
        }

        if (!$hasChildrenProp) {
            throw new ComponentValidationException(
                'children',
                $className,
                array_map(static fn($p) => $p->getName(), $reflection['properties'])
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
            $output = [];

            $hasChildren = false;
            foreach ($node->childNodes as $child) {
                if (
                    $child instanceof DOMElement ||
                    ($child instanceof DOMText && trim($child->textContent) !== '')
                ) {
                    $hasChildren = true;
                    break;
                }
            }

            if ($hasChildren) {
                $output[] = "<!-- pp-scope:{$parentContext} -->";
            }

            foreach ($node->childNodes as $child) {
                $output[] = self::processNode($child);
            }

            if ($hasChildren) {
                $output[] = "<!-- /pp-scope -->";
            }

            return trim(implode('', $output));
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
        $fragDom = self::convertToXml($html);

        self::normalizeComponentAttributes($fragDom);

        $hasEventListeners = self::hasEventListeners($incomingProps);
        $eventListeners = self::getEventListeners($incomingProps);
        $regularProps = self::getRegularProps($incomingProps);
        $existingAttributes = self::getAllExistingAttributes($fragDom);

        $needsScope = false;
        $rootElement = null;

        foreach ($fragDom->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $rootElement = $child;
                $child->setAttribute(self::COMPONENT_ATTRIBUTE, $sectionId);

                foreach ($regularProps as $propName => $propValue) {
                    $attrName = self::containsMustacheSyntax($propValue)
                        ? self::camelToKebab($propName)
                        : $propName;

                    if (
                        $child->hasAttribute($propName) ||
                        $child->hasAttribute(self::camelToKebab($propName)) ||
                        isset($existingAttributes[$propName]) ||
                        isset($existingAttributes[self::camelToKebab($propName)])
                    ) {
                        continue;
                    }

                    $child->setAttribute($attrName, $propValue);
                }

                $hasRegularProps = !empty($regularProps);
                if (!empty($parentContext) && ($hasRegularProps || $hasEventListeners)) {
                    $needsScope = true;
                }

                if ($hasEventListeners) {
                    foreach ($eventListeners as $eventName => $eventHandler) {
                        $kebabEventName = self::camelToKebab($eventName);

                        if (
                            isset($existingAttributes[$eventName]) ||
                            isset($existingAttributes[$kebabEventName])
                        ) {
                            continue;
                        }

                        if (self::containsMustacheSyntax($eventHandler)) {
                            if ($child->hasAttribute($eventName)) {
                                $child->removeAttribute($eventName);
                            }

                            $child->setAttribute($kebabEventName, $eventHandler);
                        } else {
                            $child->setAttribute($eventName, $eventHandler);
                        }
                    }
                }

                break;
            }
        }

        if ($hasEventListeners && !empty($parentContext)) {
            self::wrapEventElementsWithScope(
                $fragDom,
                $eventListeners,
                $parentContext,
                $sectionId
            );
        }

        $htmlOut = self::innerXml($fragDom);

        if ($needsScope && $rootElement && !empty($parentContext)) {
            $htmlOut = "<!-- pp-scope:{$parentContext} -->\n{$htmlOut}\n<!-- /pp-scope -->";
        }

        $htmlOut = self::normalizeSelfClosingTags($htmlOut);

        if (self::needsRecompilation($htmlOut)) {
            $htmlOut = self::compile($htmlOut);
        }

        return $htmlOut;
    }

    private static function wrapEventElementsWithScope(
        DOMDocument $fragDom,
        array $eventListeners,
        string $parentContext,
        string $sectionId
    ): void {
        $xpath = new DOMXPath($fragDom);

        foreach ($eventListeners as $eventName => $eventHandler) {
            $kebabEventName = self::camelToKebab($eventName);

            $eventElements = $xpath->query("//*[@{$eventName} or @{$kebabEventName}]");

            foreach ($eventElements as $element) {
                if ($element instanceof DOMElement) {
                    $hasParentEvent = false;

                    if ($element->hasAttribute($eventName)) {
                        $attrValue = $element->getAttribute($eventName);
                        if (
                            $attrValue === $eventHandler ||
                            (self::containsMustacheSyntax($eventHandler) && $attrValue === $eventHandler)
                        ) {
                            $hasParentEvent = true;
                        }
                    }

                    if (!$hasParentEvent && $element->hasAttribute($kebabEventName)) {
                        $attrValue = $element->getAttribute($kebabEventName);
                        if (
                            $attrValue === $eventHandler ||
                            (self::containsMustacheSyntax($eventHandler) && $attrValue === $eventHandler)
                        ) {
                            $hasParentEvent = true;
                        }
                    }

                    if ($hasParentEvent) {
                        $parent = $element->parentNode;
                        $openComment = $element->ownerDocument->createComment(" pp-scope:{$parentContext} ");
                        $parent->insertBefore($openComment, $element);
                        $closeComment = $element->ownerDocument->createComment(" /pp-scope ");
                        $nextSibling = $element->nextSibling;
                        if ($nextSibling) {
                            $parent->insertBefore($closeComment, $nextSibling);
                        } else {
                            $parent->appendChild($closeComment);
                        }
                    }
                }
            }
        }
    }

    private static function normalizeComponentAttributes(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $allElements = $xpath->query('//*');

        foreach ($allElements as $element) {
            if (!($element instanceof DOMElement)) continue;

            $attributesToRename = [];

            foreach ($element->attributes as $attr) {
                $attrName = $attr->name;
                $value = $attr->value;

                if (!self::containsMustacheSyntax($value)) {
                    continue;
                }

                $kebabName = self::camelToKebab($attrName);
                if ($kebabName !== $attrName) {
                    $attributesToRename[$attrName] = [
                        'kebabName' => $kebabName,
                        'value' => $value
                    ];
                }
            }

            foreach ($attributesToRename as $oldName => $info) {
                $element->removeAttribute($oldName);
                $element->setAttribute($info['kebabName'], $info['value']);
            }
        }
    }

    private static function getAllExistingAttributes(DOMDocument $fragDom): array
    {
        $existingAttributes = [];

        $xpath = new DOMXPath($fragDom);
        $allElements = $xpath->query('//*[@*]');

        foreach ($allElements as $element) {
            if ($element instanceof DOMElement) {
                foreach ($element->attributes as $attr) {
                    $existingAttributes[$attr->name] = true;
                }
            }
        }

        return $existingAttributes;
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

    private static function getRegularProps(array $props): array
    {
        $regularProps = [];
        foreach ($props as $key => $value) {
            if (
                !isset(self::SYSTEM_PROPS[$key]) &&
                $key !== 'children' &&
                !(str_starts_with(strtolower($key), 'on') && strlen($key) > 2)
            ) {
                $regularProps[$key] = $value;
            }
        }
        return $regularProps;
    }

    private static function hasEventListeners(array $props): bool
    {
        foreach ($props as $key => $value) {
            if (str_starts_with(strtolower($key), 'on') && strlen($key) > 2) {
                return true;
            }
        }
        return false;
    }

    private static function getEventListeners(array $props): array
    {
        $eventListeners = [];
        foreach ($props as $key => $value) {
            if (str_starts_with(strtolower($key), 'on') && strlen($key) > 2) {
                $eventListeners[$key] = $value;
            }
        }
        return $eventListeners;
    }

    private static function needsRecompilation(string $html): bool
    {
        return preg_match(self::COMPONENT_TAG_REGEX, $html) === 1 ||
            stripos($html, '<script') !== false;
    }

    private static function normalizeSelfClosingTags(string $html): string
    {
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

            self::$reflectionCache[$className] = [
                'class' => $rc,
                'constructor' => $rc->getConstructor(),
                'properties' => $publicProps,
                'allowedProps' => self::SYSTEM_PROPS + array_flip(
                    array_map(static fn($p) => $p->getName(), $publicProps)
                ),
            ];
        }

        return self::$reflectionCache[$className];
    }

    private static function validateComponentProps(string $className, array $attributes): void
    {
        $reflection = self::getClassReflection($className);

        foreach ($reflection['properties'] as $prop) {
            $name = $prop->getName();
            $type = $prop->getType();

            if (
                $type instanceof ReflectionNamedType &&
                $type->isBuiltin() &&
                !$type->allowsNull() &&
                !array_key_exists($name, $attributes)
            ) {
                throw new ComponentValidationException(
                    $name,
                    $className,
                    array_map(static fn($p) => $p->getName(), $reflection['properties'])
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

        $currentFile = str_replace('\\', '/', Bootstrap::$contentToInclude);

        foreach ($mappings as $entry) {
            if (isset($entry['importer'])) {
                $importerPath = str_replace('\\', '/', $entry['importer']);

                if ($importerPath === $currentFile) {
                    return $entry;
                }
            }
        }

        $srcNorm = str_replace('\\', '/', SRC_PATH);
        $currentRelative = str_replace($srcNorm, '', $currentFile);
        $currentRelative = ltrim($currentRelative, '/');

        foreach ($mappings as $entry) {
            if (isset($entry['importer'])) {
                $importerPath = str_replace('\\', '/', $entry['importer']);
                $importerRelative = str_replace($srcNorm, '', $importerPath);
                $importerRelative = ltrim($importerRelative, '/');

                if ($importerRelative === $currentRelative) {
                    return $entry;
                }
            }
        }

        return $mappings[0];
    }

    public static function innerXml(DOMNode $node): string
    {
        if ($node instanceof DOMDocument) {
            $node = $node->documentElement;
        }

        $parts = [];
        foreach ($node->childNodes as $child) {
            $parts[] = $node->ownerDocument->saveXML($child);
        }

        return implode('', $parts);
    }

    private static function preprocessFragmentSyntax(string $content): string
    {
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
        $systemProps = $systemProps ?: self::SYSTEM_PROPS;

        if (isset($systemProps[$string]) || str_contains($string, '-')) {
            return $string;
        }

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $string));
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
