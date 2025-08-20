<?php

declare(strict_types=1);

namespace Lib\PHPX;

use Lib\PrismaPHPSettings;
use Lib\MainLayout;
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
use Lib\PHPX\TypeCoercer;
use Lib\PHPX\Exceptions\ComponentValidationException;

class TemplateCompiler
{
    private const BINDING_REGEX = '/\{\{\s*((?:(?!\{\{|\}\})[\s\S])*?)\s*\}\}/uS';
    private const ATTRIBUTE_REGEX = '/(\s[\w:-]+=)([\'"])(.*?)\2/s';
    private const MUSTACHE_REGEX = '/\{\{[\s\S]*?\}\}/u';
    private const NAMED_ENTITY_REGEX = '/&([a-zA-Z][a-zA-Z0-9]+);/';
    private const COMPONENT_TAG_REGEX = '/<\/*[A-Z][\w-]*/u';
    private const SELF_CLOSING_REGEX = '/<([a-z0-9-]+)([^>]*)\/>/i';
    private const SCRIPT_REGEX = '#<script\b([^>]*?)>(.*?)</script>#is';
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
        'ref' => true,
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
        'text/php' => true,
    ];

    private static array $classMappings = [];
    private static array $reflectionCache = [];
    private static array $sectionStack = [];
    private static int $compileDepth = 0;
    private static array $componentInstanceCounts = [];

    public static function compile(string $templateContent): string
    {
        if (self::$compileDepth === 0) {
            self::$componentInstanceCounts = [];
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
            !isset($_SERVER['HTTP_X_PPHP_NAVIGATION']) &&
            !PrismaPHPSettings::$option->backendOnly
        ) {
            $htmlContent = preg_replace(
                self::BODY_PATTERNS['open'],
                '<body$1 hidden>',
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
        return self::escapeMustacheAngles(
            self::escapeAttributeAngles(
                self::escapeLiteralTextContent(
                    self::escapeAmpersands(
                        self::normalizeNamedEntities(
                            self::protectInlineScripts($content)
                        )
                    )
                )
            )
        );
    }

    private static function createDomFromXml(string $xml): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        if (!$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET)) {
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

    private static function escapeAmpersands(string $content): string
    {
        return self::processCDataAwareParts(
            $content,
            fn($part) => preg_replace(
                '/&(?![a-zA-Z][A-Za-z0-9]*;|#[0-9]+;|#x[0-9A-Fa-f]+;)/',
                '&amp;',
                $part
            )
        );
    }

    private static function escapeAttributeAngles(string $html): string
    {
        return preg_replace_callback(
            self::ATTRIBUTE_REGEX,
            static fn($m) => $m[1] . $m[2] .
                str_replace(['<', '>'], ['&lt;', '&gt;'], $m[3]) . $m[2],
            $html
        );
    }

    private static function escapeLiteralTextContent(string $content): string
    {
        $literalTags = implode('|', array_keys(self::LITERAL_TEXT_TAGS));
        $pattern = '/(<(?:' . $literalTags . ')\b[^>]*>)(.*?)(<\/(?:' . $literalTags . ')>)/is';

        return preg_replace_callback(
            $pattern,
            static function ($matches) {
                $openTag = $matches[1];
                $textContent = $matches[2];
                $closeTag = $matches[3];

                $escapedContent = preg_replace_callback(
                    '/(\s|^|,)([<>]=?)(\s|$|,)/',
                    function ($match) {
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

    private static function escapeMustacheAngles(string $content): string
    {
        return preg_replace_callback(
            self::MUSTACHE_REGEX,
            static fn($m) => str_replace(['<', '>'], ['&lt;', '&gt;'], $m[0]),
            $content
        );
    }

    private static function normalizeNamedEntities(string $html): string
    {
        return self::processCDataAwareParts(
            $html,
            static function (string $part): string {
                return preg_replace_callback(
                    self::NAMED_ENTITY_REGEX,
                    static function (array $m): string {
                        $decoded = html_entity_decode($m[0], ENT_HTML5, 'UTF-8');
                        if ($decoded === $m[0]) {
                            return $m[0];
                        }

                        $code = function_exists('mb_ord')
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
        $parts = preg_split('/(<!\[CDATA\[[\s\S]*?\]\]>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        foreach ($parts as $i => $part) {
            if (!str_starts_with($part, '<![CDATA[')) {
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
            if (preg_match('/\bsrc\s*=/i', $m[1])) {
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
        if (preg_match('/\btype\s*=\s*([\'"]?)([^\'"\s>]+)/i', $attributes, $matches)) {
            return strtolower($matches[2]);
        }
        return '';
    }

    private static function processScriptsInContent(string $content, callable $callback): string
    {
        return preg_replace_callback(self::SCRIPT_REGEX, $callback, $content) ?? $content;
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
            $node->setAttribute('type', 'text/php');
        }

        // Handle component sections
        if ($node->hasAttribute('pp-component')) {
            self::$sectionStack[] = $node->getAttribute('pp-component');
            $pushed = true;
        }

        try {
            self::processAttributes($node);

            if (isset(self::$classMappings[$node->nodeName])) {
                return self::renderComponent(
                    $node,
                    $node->nodeName,
                    self::getNodeAttributes($node)
                );
            }

            $children = implode('', self::processChildNodes($node->childNodes));
            $attrs = self::getNodeAttributes($node) + ['children' => $children];

            return self::renderAsHtml($node->nodeName, $attrs);
        } finally {
            if ($pushed) {
                array_pop(self::$sectionStack);
            }
        }
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

        return preg_replace_callback(
            self::BINDING_REGEX,
            static fn($m) => self::processBindingExpression(trim($m[1])),
            $node->textContent
        );
    }

    private static function processBindingExpression(string $expr): string
    {
        $escaped = htmlspecialchars($expr, ENT_QUOTES, 'UTF-8');
        $attribute = preg_match('/^[\w.]+$/u', $expr) ? 'pp-bind' : 'pp-bind-expr';

        return "<span {$attribute}=\"{$escaped}\"></span>";
    }

    protected static function renderComponent(
        DOMElement $node,
        string $componentName,
        array $incomingProps
    ): string {
        $mapping = self::selectComponentMapping($componentName);
        $sectionId = self::generateSectionId($mapping['className']);

        $originalStack = self::$sectionStack;
        self::$sectionStack[] = $sectionId;

        try {
            PHPX::setRenderingContext($originalStack, $sectionId);

            $instance = self::initializeComponentInstance($mapping, $incomingProps);
            $instance->children = self::getChildrenHtml($node);

            PHPX::setRenderingContext($originalStack, $sectionId);

            return self::compileComponentHtml($instance->render(), $sectionId);
        } finally {
            self::$sectionStack = $originalStack;
        }
    }

    private static function generateSectionId(string $className): string
    {
        $baseId = 's' . base_convert(sprintf('%u', crc32($className)), 10, 36);
        $idx = self::$componentInstanceCounts[$baseId] ?? 0;
        self::$componentInstanceCounts[$baseId] = $idx + 1;

        return $idx === 0 ? $baseId : "{$baseId}{$idx}";
    }

    private static function getChildrenHtml(DOMElement $node): string
    {
        $output = [];
        foreach ($node->childNodes as $child) {
            $output[] = self::processNode($child);
        }
        return trim(implode('', $output));
    }

    private static function compileComponentHtml(string $html, string $sectionId): string
    {
        $html = self::preprocessFragmentSyntax($html);
        $fragDom = self::convertToXml($html);

        foreach ($fragDom->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $child->setAttribute('pp-component', $sectionId);
                break;
            }
        }

        $htmlOut = self::innerXml($fragDom);
        $htmlOut = self::normalizeSelfClosingTags($htmlOut);

        if (self::needsRecompilation($htmlOut)) {
            $htmlOut = self::compile($htmlOut);
        }

        return $htmlOut;
    }

    private static function needsRecompilation(string $html): bool
    {
        return str_contains($html, '{{') ||
            preg_match(self::COMPONENT_TAG_REGEX, $html) === 1 ||
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

        $instance = $reflection['class']->newInstanceWithoutConstructor();
        self::setInstanceProperties($instance, $attributes, $reflection['properties']);

        $reflection['constructor']?->invoke($instance, $attributes);

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

    private static function setInstanceProperties(
        object $instance,
        array $attributes,
        array $properties
    ): void {
        foreach ($properties as $prop) {
            $name = $prop->getName();
            if (array_key_exists($name, $attributes)) {
                $value = TypeCoercer::coerce($attributes[$name], $prop->getType());
                $prop->setValue($instance, $value);
            }
        }
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

        $srcNorm = str_replace('\\', '/', SRC_PATH) . '/';
        $relImp = str_replace($srcNorm, '', str_replace('\\', '/', Bootstrap::$contentToInclude));

        foreach ($mappings as $entry) {
            if (isset($entry['importer'])) {
                $imp = str_replace([$srcNorm, '\\'], ['', '/'], $entry['importer']);
                if ($imp === $relImp) {
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

        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveXML($child);
        }

        return $html;
    }

    private static function preprocessFragmentSyntax(string $content): string
    {
        return str_replace(['<>', '</>'], ['<Fragment>', '</Fragment>'], $content);
    }

    private static function processAttributes(DOMElement $node): void
    {
        foreach ($node->attributes as $attr) {
            if (preg_match(self::BINDING_REGEX, $attr->value, $matches)) {
                $node->setAttribute("pp-bind-{$attr->name}", trim($matches[1]));
            }
        }
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

        if (empty($attrs)) {
            $attrStr = '';
        } else {
            $pairs = [];
            foreach ($attrs as $name => $value) {
                $pairs[] = sprintf(
                    '%s="%s"',
                    $name,
                    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }
            $attrStr = ' ' . implode(' ', $pairs);
        }

        return isset(self::SELF_CLOSING_TAGS[strtolower($tag)])
            ? "<{$tag}{$attrStr} />"
            : "<{$tag}{$attrStr}>{$children}</{$tag}>";
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
