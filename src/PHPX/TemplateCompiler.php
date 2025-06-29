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
use ReflectionType;
use ReflectionNamedType;
use Lib\PHPX\TypeCoercer;
use Lib\PHPX\Exceptions\ComponentValidationException;

class TemplateCompiler
{
    protected const BINDING_REGEX = '/\{\{\s*((?:(?!\{\{|\}\})[\s\S])*?)\s*\}\}/uS';
    private const LITERAL_TEXT_TAGS = [
        'code' => true,
        'pre'  => true,
        'samp' => true,
        'kbd'  => true,
        'var'  => true,
    ];
    private const SYSTEM_PROPS = [
        'children' => true,
        'key' => true,
        'ref' => true,
    ];

    protected static array $classMappings = [];
    protected static array $selfClosingTags = [
        'area',
        'base',
        'br',
        'col',
        'command',
        'embed',
        'hr',
        'img',
        'input',
        'keygen',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr'
    ];
    private static array $sectionStack = [];
    private static int $compileDepth = 0;
    private static array $componentInstanceCounts = [];
    private static array $reflections       = [];
    private static array $constructors      = [];
    private static array $publicProperties  = [];
    private static array $allowedProps = [];

    public static function compile(string $templateContent): string
    {
        if (self::$compileDepth === 0) {
            self::$componentInstanceCounts = [];
        }
        self::$compileDepth++;

        if (empty(self::$classMappings)) {
            self::initializeClassMappings();
        }

        $dom = self::convertToXml($templateContent);
        $root = $dom->documentElement;

        $output = [];
        foreach ($root->childNodes as $child) {
            $output[] = self::processNode($child);
        }

        self::$compileDepth--;
        return implode('', $output);
    }

    public static function injectDynamicContent(string $htmlContent): string
    {
        $headOpenPattern = '/(<head\b[^>]*>)/i';

        $htmlContent = preg_replace(
            $headOpenPattern,
            '$1' . MainLayout::outputMetadata(),
            $htmlContent,
            1
        );

        $headClosePattern = '/(<\/head\s*>)/i';
        $headScripts      = MainLayout::outputHeadScripts();
        $htmlContent = preg_replace(
            $headClosePattern,
            $headScripts . '$1',
            $htmlContent,
            1
        );

        if (!isset($_SERVER['HTTP_X_PPHP_NAVIGATION'])) {
            $htmlContent = preg_replace(
                '/<body([^>]*)>/i',
                '<body$1 hidden>',
                $htmlContent,
                1
            );
        }

        $bodyClosePattern = '/(<\/body\s*>)/i';

        $htmlContent = preg_replace(
            $bodyClosePattern,
            MainLayout::outputFooterScripts() . '$1',
            $htmlContent,
            1
        );

        return $htmlContent;
    }

    private static function escapeAmpersands(string $content): string
    {
        return preg_replace(
            '/&(?![a-zA-Z][A-Za-z0-9]*;|#[0-9]+;|#x[0-9A-Fa-f]+;)/',
            '&amp;',
            $content
        );
    }

    private static function escapeAttributeAngles(string $html): string
    {
        return preg_replace_callback(
            '/(\s[\w:-]+=)([\'"])(.*?)\2/s',
            fn($m) => $m[1] . $m[2] . str_replace(['<', '>'], ['&lt;', '&gt;'], $m[3]) . $m[2],
            $html
        );
    }

    private static function escapeMustacheAngles(string $content): string
    {
        return preg_replace_callback(
            '/\{\{[\s\S]*?\}\}/u',
            fn($m) => str_replace(['<', '>'], ['&lt;', '&gt;'], $m[0]),
            $content
        );
    }

    public static function convertToXml(string $templateContent): DOMDocument
    {
        $content = self::protectInlineScripts($templateContent);
        $content = self::normalizeNamedEntities($content);

        $content = self::escapeAmpersands($content);
        $content = self::escapeAttributeAngles($content);
        $content = self::escapeMustacheAngles($content);

        $xml = "<root>{$content}</root>";

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET)) {
            throw new RuntimeException(
                'XML Parsing Failed: ' . implode('; ', self::getXmlErrors())
            );
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $dom;
    }

    private static function normalizeNamedEntities(string $html): string
    {
        return preg_replace_callback(
            '/&([a-zA-Z][a-zA-Z0-9]+);/',
            static function (array $m): string {
                $decoded = html_entity_decode($m[0], ENT_HTML5, 'UTF-8');

                if ($decoded === $m[0]) {
                    return $m[0];
                }

                if (function_exists('mb_ord')) {
                    return '&#' . mb_ord($decoded, 'UTF-8') . ';';
                }

                $code = unpack('N', mb_convert_encoding($decoded, 'UCS-4BE', 'UTF-8'))[1];
                return '&#' . $code . ';';
            },
            $html
        );
    }

    private static function protectInlineScripts(string $html): string
    {
        return preg_replace_callback(
            '#<script\b([^>]*?)>(.*?)</script>#is',
            static function ($m) {
                if (preg_match('/\bsrc\s*=/i', $m[1])) {
                    return $m[0];
                }

                if (strpos($m[2], '<![CDATA[') !== false) {
                    return $m[0];
                }

                if (preg_match('/\btype\s*=\s*(["\']?)(?!text\/|application\/javascript|module)/i', $m[1])) {
                    return $m[0];
                }

                $code = str_replace(']]>', ']]]]><![CDATA[>', $m[2]);

                return "<script{$m[1]}><![CDATA[\n{$code}\n]]></script>";
            },
            $html
        );
    }

    public static function innerXml(DOMNode $node): string
    {
        if ($node instanceof DOMDocument) {
            $node = $node->documentElement;
        }

        /** @var DOMDocument $doc */
        $doc  = $node->ownerDocument;

        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveXML($child);
        }
        return $html;
    }

    protected static function getXmlErrors(): array
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        return array_map(fn($e) => self::formatLibxmlError($e), $errors);
    }

    protected static function formatLibxmlError(LibXMLError $error): string
    {
        $type = match ($error->level) {
            LIBXML_ERR_WARNING => 'Warning',
            LIBXML_ERR_ERROR   => 'Error',
            LIBXML_ERR_FATAL   => 'Fatal',
            default            => 'Unknown',
        };
        return sprintf(
            "[%s] Line %d, Col %d: %s",
            $type,
            $error->line,
            $error->column,
            trim($error->message)
        );
    }

    protected static function processNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return self::processTextNode($node);
        }

        if ($node instanceof DOMElement) {
            $pushed = false;
            $tag    = strtolower($node->nodeName);

            if (
                $tag === 'script' &&
                !$node->hasAttribute('src') &&
                !$node->hasAttribute('type')
            ) {
                $node->setAttribute('type', 'text/php');
            }

            if ($node->hasAttribute('pp-component')) {
                self::$sectionStack[] = $node->getAttribute('pp-component');
                $pushed = true;
            }

            self::processAttributes($node);

            if (isset(self::$classMappings[$node->nodeName])) {
                $html = self::renderComponent(
                    $node,
                    $node->nodeName,
                    self::getNodeAttributes($node)
                );
                if ($pushed) {
                    array_pop(self::$sectionStack);
                }
                return $html;
            }

            $children = '';
            foreach ($node->childNodes as $c) {
                $children .= self::processNode($c);
            }
            $attrs = self::getNodeAttributes($node) + ['children' => $children];
            $out   = self::renderAsHtml($node->nodeName, $attrs);

            if ($pushed) {
                array_pop(self::$sectionStack);
            }
            return $out;
        }

        if ($node instanceof DOMComment) {
            return "<!--{$node->textContent}-->";
        }

        return $node->textContent;
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
            fn($m) => self::processBindingExpression(trim($m[1])),
            $node->textContent
        );
    }

    private static function processAttributes(DOMElement $node): void
    {
        foreach ($node->attributes as $a) {
            if (!preg_match(self::BINDING_REGEX, $a->value, $m)) {
                continue;
            }

            $rawExpr = trim($m[1]);
            $node->setAttribute("pp-bind-{$a->name}", $rawExpr);
        }
    }

    private static function processBindingExpression(string $expr): string
    {
        $escaped = htmlspecialchars($expr, ENT_QUOTES, 'UTF-8');

        if (preg_match('/^[\w.]+$/u', $expr)) {
            return "<span pp-bind=\"{$escaped}\"></span>";
        }

        return "<span pp-bind-expr=\"{$escaped}\"></span>";
    }

    protected static function renderComponent(
        DOMElement $node,
        string $componentName,
        array $incomingProps
    ): string {
        $mapping       = self::selectComponentMapping($componentName);
        $instance      = self::initializeComponentInstance($mapping, $incomingProps);

        $childHtml = '';
        foreach ($node->childNodes as $c) {
            $childHtml .= self::processNode($c);
        }

        $instance->children = $childHtml;

        $baseId   = 's' . base_convert(sprintf('%u', crc32($mapping['className'])), 10, 36);
        $idx      = self::$componentInstanceCounts[$baseId] ?? 0;
        self::$componentInstanceCounts[$baseId] = $idx + 1;
        $sectionId = $idx === 0 ? $baseId : "{$baseId}{$idx}";

        $html     = $instance->render();
        $fragDom  = self::convertToXml($html);
        $root = $fragDom->documentElement;
        foreach ($root->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $c->setAttribute('pp-component', $sectionId);
                break;
            }
        }

        $htmlOut = self::innerXml($fragDom);
        if (
            str_contains($htmlOut, '{{') ||
            self::hasComponentTag($htmlOut) ||
            stripos($htmlOut, '<script') !== false
        ) {
            $htmlOut = self::compile($htmlOut);
        }

        return $htmlOut;
    }

    private static function selectComponentMapping(string $componentName): array
    {
        if (!isset(self::$classMappings[$componentName])) {
            throw new RuntimeException("Component {$componentName} not registered");
        }
        $mappings = self::$classMappings[$componentName];

        $srcNorm = str_replace('\\', '/', SRC_PATH) . '/';
        $relImp  = str_replace($srcNorm, '', str_replace('\\', '/', Bootstrap::$contentToInclude));

        if (isset($mappings[0]) && is_array($mappings[0])) {
            foreach ($mappings as $entry) {
                $imp = isset($entry['importer'])
                    ? str_replace('\\', '/', $entry['importer'])
                    : '';
                if (str_replace($srcNorm, '', $imp) === $relImp) {
                    return $entry;
                }
            }
            return $mappings[0];
        }
        return $mappings;
    }

    protected static function initializeComponentInstance(array $mapping, array $attributes)
    {
        if (!isset($mapping['className'], $mapping['filePath'])) {
            throw new RuntimeException("Invalid mapping");
        }

        $className = $mapping['className'];
        $filePath  = $mapping['filePath'];

        require_once str_replace('\\', '/', SRC_PATH . '/' . $filePath);
        if (!class_exists($className)) {
            throw new RuntimeException("Class {$className} not found");
        }

        self::cacheClassReflection($className);

        if (!isset(self::$reflections[$className])) {
            $rc = new ReflectionClass($className);
            self::$reflections[$className]      = $rc;
            self::$constructors[$className]     = $rc->getConstructor();
            self::$publicProperties[$className] = array_filter(
                $rc->getProperties(ReflectionProperty::IS_PUBLIC),
                fn(ReflectionProperty $p) => !$p->isStatic()
            );
        }

        self::validateComponentProps($className, $attributes);

        $ref  = self::$reflections[$className];
        $ctor = self::$constructors[$className];
        $inst = $ref->newInstanceWithoutConstructor();

        foreach (self::$publicProperties[$className] as $prop) {
            $name = $prop->getName();

            if (!array_key_exists($name, $attributes)) {
                continue;
            }
            $value = self::coerce($attributes[$name], $prop->getType());
            $prop->setValue($inst, $value);
        }

        if ($ctor) {
            $ctor->invoke($inst, $attributes);
        }

        return $inst;
    }

    private static function cacheClassReflection(string $className): void
    {
        if (isset(self::$reflections[$className])) {
            return;
        }

        $rc = new ReflectionClass($className);
        self::$reflections[$className] = $rc;
        self::$constructors[$className] = $rc->getConstructor();

        $publicProps = array_filter(
            $rc->getProperties(ReflectionProperty::IS_PUBLIC),
            fn(ReflectionProperty $p) => !$p->isStatic()
        );
        self::$publicProperties[$className] = $publicProps;

        $allowed = self::SYSTEM_PROPS;
        foreach ($publicProps as $prop) {
            $allowed[$prop->getName()] = true;
        }
        self::$allowedProps[$className] = $allowed;
    }

    private static function validateComponentProps(string $className, array $attributes): void
    {
        foreach (self::$publicProperties[$className] as $prop) {
            $name = $prop->getName();
            $type = $prop->getType();

            if (
                $type instanceof ReflectionNamedType && $type->isBuiltin()
                && ! $type->allowsNull()
                && ! array_key_exists($name, $attributes)
            ) {
                throw new ComponentValidationException(
                    $name,
                    $className,
                    array_map(fn($p) => $p->getName(), self::$publicProperties[$className])
                );
            }
        }

        return;
    }

    private static function coerce(mixed $value, ?ReflectionType $type): mixed
    {
        return TypeCoercer::coerce($value, $type);
    }

    protected static function initializeClassMappings(): void
    {
        foreach (PrismaPHPSettings::$classLogFiles as $tag => $cls) {
            self::$classMappings[$tag] = $cls;
        }
    }

    protected static function hasComponentTag(string $html): bool
    {
        return preg_match('/<\/*[A-Z][\w-]*/u', $html) === 1;
    }

    private static function getNodeAttributes(DOMElement $node): array
    {
        $out = [];
        foreach ($node->attributes as $a) {
            $out[$a->name] = $a->value;
        }
        return $out;
    }

    private static function renderAsHtml(string $tag, array $attrs): string
    {
        $pairs = [];
        foreach ($attrs as $k => $v) {
            if ($k === 'children') {
                continue;
            }
            $pairs[] = sprintf(
                '%s="%s"',
                $k,
                htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }
        $attrStr = $pairs ? ' ' . implode(' ', $pairs) : '';

        return in_array(strtolower($tag), self::$selfClosingTags, true)
            ? "<{$tag}{$attrStr} />"
            : "<{$tag}{$attrStr}>{$attrs['children']}</{$tag}>";
    }
}
