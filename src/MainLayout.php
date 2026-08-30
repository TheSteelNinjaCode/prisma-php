<?php

declare(strict_types=1);

namespace PP;

use PP\Set;
use PP\PHPX\TemplateCompiler;

class MainLayout
{
    public static string $title = '';
    public static string $description = '';
    public static string $children = '';
    public static string $html = '';

    /** @var Set<string>|null */
    private static ?Set $headScripts = null;
    /** @var list<string>|null */
    private static ?array $footerScripts = null;
    private static array $customMetadata = [];
    private static array $processedScripts = [];
    private static int $footerComponentCounter = 0;
    private static ?string $headScriptsOutputCache = null;
    private static ?string $footerScriptsOutputCache = null;
    private static ?array $systemPropsCache = null;
    private static array $footerScriptHashCache = [];
    private static array $footerScriptAttributesCache = [];
    private static array $preparedHeadScriptCache = [];

    public static function init(): void
    {
        if (self::$headScripts === null) {
            self::$headScripts = new Set();
        }
        if (self::$footerScripts === null) {
            self::$footerScripts = [];
        }
        self::$processedScripts = [];
        self::$headScriptsOutputCache = null;
        self::$footerScriptsOutputCache = null;
    }

    /**
     * Adds one or more scripts to the head section if they are not already present.
     *
     * @param string ...$scripts The scripts to be added to the head section.
     * @return void
     */
    public static function addHeadScript(string ...$scripts): void
    {
        foreach ($scripts as $script) {
            self::$headScripts->add(self::prepareHeadScript($script));
        }

        self::$headScriptsOutputCache = null;
    }

    /**
     * Adds one or more scripts to the footer section if they are not already present.
     *
     * @param string ...$scripts One or more scripts to be added to the footer.
     * @return void
     */
    public static function addFooterScript(string ...$scripts): void
    {
        $callerClass = null;

        foreach ($scripts as $script) {
            $trimmedScript = trim($script);
            $scriptKey = $trimmedScript;

            if (isset(self::$processedScripts[$scriptKey])) {
                continue;
            }

            if (str_starts_with($trimmedScript, '<script')) {
                $script = self::prepareFooterScript($script, $callerClass);
            }

            self::$footerScripts[] = $script;
            self::$processedScripts[$scriptKey] = true;
        }

        self::$footerScriptsOutputCache = null;
    }

    /**
     * Generates all the head scripts with dynamic attributes.
     *
     * This method iterates over all registered head scripts and adds a custom dynamic attribute
     * based on the tag type (script, link, or style).
     *
     * @return string The concatenated head scripts with dynamic attributes.
     */
    public static function outputHeadScripts(): string
    {
        if (self::$headScriptsOutputCache !== null) {
            return self::$headScriptsOutputCache;
        }

        self::$headScriptsOutputCache = implode("\n", self::$headScripts->values());

        return self::$headScriptsOutputCache;
    }

    private static function prepareHeadScript(string $tag): string
    {
        if (isset(self::$preparedHeadScriptCache[$tag])) {
            return self::$preparedHeadScriptCache[$tag];
        }

        if (strpos($tag, '<script') !== false) {
            return self::$preparedHeadScriptCache[$tag]
                = str_replace('<script', '<script pp-dynamic-script="81D7D"', $tag);
        }

        if (strpos($tag, '<link') !== false) {
            return self::$preparedHeadScriptCache[$tag]
                = str_replace('<link', '<link pp-dynamic-link="81D7D"', $tag);
        }

        if (strpos($tag, '<style') !== false) {
            return self::$preparedHeadScriptCache[$tag]
                = str_replace('<style', '<style pp-dynamic-style="81D7D"', $tag);
        }

        return self::$preparedHeadScriptCache[$tag] = $tag;
    }

    /**
     * Generates all the footer scripts.
     *
     * @return string The concatenated footer scripts.
     */
    public static function outputFooterScripts(): string
    {
        if (self::$footerScriptsOutputCache !== null) {
            return self::$footerScriptsOutputCache;
        }

        self::$footerScriptsOutputCache = implode("\n", self::$footerScripts ?? []);

        return self::$footerScriptsOutputCache;
    }

    private static function prepareFooterScript(string $script, ?string &$callerClass): string
    {
        $tagStart = stripos($script, '<script');
        if ($tagStart === false) {
            return $script;
        }

        $openingTagEnd = self::findOpeningTagEnd($script, $tagStart);
        if ($openingTagEnd === null) {
            return $script;
        }

        $openingTag = substr($script, $tagStart, $openingTagEnd - $tagStart + 1);
        $hasComponentAttribute = self::tagHasAttribute($openingTag, 'pp-component');
        $openingTagHasMustache = str_contains($openingTag, '{') && str_contains($openingTag, '}');

        if (!$openingTagHasMustache) {
            if ($hasComponentAttribute) {
                return $script;
            }

            $attributeMarkup = ' pp-component="' . htmlspecialchars(
                self::buildFooterComponentId($script, self::resolveFooterCallerClass($callerClass)),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . '"';

            $insertPosition = self::getOpeningTagInsertPosition($script, $openingTagEnd);

            return substr($script, 0, $insertPosition) . $attributeMarkup . substr($script, $insertPosition);
        }

        $parsedAttrs = self::getCachedScriptAttributes($openingTag);

        if (!isset($parsedAttrs['pp-component'])) {
            $parsedAttrs['pp-component'] = self::buildFooterComponentId(
                $script,
                self::resolveFooterCallerClass($callerClass)
            );
        }

        $parsedAttrs = self::convertAttributesToKebabCase($parsedAttrs);
        $newAttrs = self::buildAttributesString($parsedAttrs);

        return substr($script, 0, $tagStart)
            . '<script' . $newAttrs . '>'
            . substr($script, $openingTagEnd + 1);
    }

    private static function getCachedScriptAttributes(string $openingTag): array
    {
        if (!isset(self::$footerScriptAttributesCache[$openingTag])) {
            $attrString = substr($openingTag, 7, -1);
            self::$footerScriptAttributesCache[$openingTag] = self::parseScriptAttributes($attrString);
        }

        return self::$footerScriptAttributesCache[$openingTag];
    }

    private static function parseScriptAttributes(string $attrString): array
    {
        $attributes = [];
        $attrString = trim($attrString);

        if (empty($attrString)) {
            return $attributes;
        }

        preg_match_all(
            "/(\w[\w:-]*)\s*(?:=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s>]+)))?/i",
            $attrString,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = $match[1];

            if (isset($match[2]) && $match[2] !== '') {
                $value = $match[2];
            } elseif (isset($match[3]) && $match[3] !== '') {
                $value = $match[3];
            } elseif (isset($match[4]) && $match[4] !== '') {
                $value = $match[4];
            } else {
                $value = '';
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    private static function convertAttributesToKebabCase(array $attributes): array
    {
        $converted = [];
        $systemProps = self::$systemPropsCache ??= TemplateCompiler::getSystemProps();

        foreach ($attributes as $name => $value) {
            if (isset($systemProps[$name])) {
                $converted[$name] = $value;
                continue;
            }

            if (TemplateCompiler::containsMustacheSyntax((string)$value)) {
                $kebabName = TemplateCompiler::camelToKebab($name, $systemProps);
                $converted[$kebabName] = $value;
            } else {
                $converted[$name] = $value;
            }
        }

        return $converted;
    }

    private static function buildAttributesString(array $attributes): string
    {
        if (empty($attributes)) {
            return '';
        }

        $pairs = [];
        foreach ($attributes as $name => $value) {
            if ($value === '') {
                $pairs[] = $name;
            } else {
                $escapedValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $pairs[] = sprintf('%s="%s"', $name, $escapedValue);
            }
        }

        return ' ' . implode(' ', $pairs);
    }

    /**
     * Clears all head scripts.
     *
     * @return void
     */
    public static function clearHeadScripts(): void
    {
        self::$headScripts->clear();
        self::$headScriptsOutputCache = null;
    }

    /**
     * Clears all footer scripts.
     *
     * @return void
     */
    public static function clearFooterScripts(): void
    {
        self::$footerScripts = [];
        self::$processedScripts = [];
        self::$footerComponentCounter = 0;
        self::$footerScriptsOutputCache = null;
    }

    private static function resolveFooterCallerClass(?string &$callerClass): string
    {
        if ($callerClass !== null) {
            return $callerClass;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        foreach ($trace as $frame) {
            $frameClass = $frame['class'] ?? null;

            if ($frameClass !== null && $frameClass !== self::class) {
                $callerClass = $frameClass;
                return $callerClass;
            }
        }

        $callerClass = 'Unknown';

        return $callerClass;
    }

    private static function buildFooterComponentId(string $script, string $callerClass): string
    {
        $scriptHash = self::$footerScriptHashCache[$script] ??= substr(md5($script), 0, 8);
        $encodedClass = 's' . base_convert(
            sprintf('%u', crc32($callerClass . self::$footerComponentCounter . $scriptHash)),
            10,
            36
        );
        self::$footerComponentCounter++;

        return $encodedClass;
    }

    private static function tagHasAttribute(string $tagMarkup, string $attributeName): bool
    {
        return preg_match('/\b' . preg_quote($attributeName, '/') . '\s*=\s*/i', $tagMarkup) === 1;
    }

    private static function findOpeningTagEnd(string $markup, int $tagStart): ?int
    {
        $length = strlen($markup);
        $quote = null;

        for ($index = $tagStart + 1; $index < $length; $index++) {
            $char = $markup[$index];

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

    private static function getOpeningTagInsertPosition(string $markup, int $openingTagEnd): int
    {
        $insertPosition = $openingTagEnd;

        while ($insertPosition > 0 && ctype_space($markup[$insertPosition - 1])) {
            $insertPosition--;
        }

        if ($insertPosition > 0 && $markup[$insertPosition - 1] === '/') {
            return $insertPosition - 1;
        }

        return $insertPosition;
    }

    /**
     * Adds custom metadata.
     *
     * @param string $key   The metadata key.
     * @param string $value The metadata value.
     * @return void
     */
    public static function addCustomMetadata(string $key, string $value): void
    {
        self::$customMetadata[$key] = $value;
    }

    /**
     * Retrieves custom metadata by key.
     *
     * @param string $key The metadata key.
     * @return string|null The metadata value or null if the key does not exist.
     */
    public static function getCustomMetadata(string $key): ?string
    {
        return self::$customMetadata[$key] ?? null;
    }

    /**
     * Generates the metadata as meta tags for the head section.
     *
     * This method includes default tags for charset and viewport, a title tag,
     * and additional metadata. If a description is not already set in the custom metadata,
     * it will use the class's description property.
     *
     * @return string The concatenated meta tags.
     */
    public static function outputMetadata(): string
    {
        $metadataContent = [
            '<meta charset="UTF-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
        ];
        $metadataContent[] = '<title>' . htmlspecialchars(self::$title) . '</title>';

        if (!isset(self::$customMetadata['description'])) {
            self::$customMetadata['description'] = self::$description;
        }

        foreach (self::$customMetadata as $key => $value) {
            $metadataContent[] = '<meta name="' . htmlspecialchars($key) . '" content="' . htmlspecialchars($value) . '" pp-dynamic-meta="81D7D">';
        }

        return implode("\n", $metadataContent);
    }

    /**
     * Clears all custom metadata.
     *
     * @return void
     */
    public static function clearCustomMetadata(): void
    {
        self::$customMetadata = [];
    }
}
