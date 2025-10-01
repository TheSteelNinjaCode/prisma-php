<?php

declare(strict_types=1);

namespace PP;

use PP\Set;

class MainLayout
{
    public static string $title = '';
    public static string $description = '';
    public static string $children = '';
    public static string $childLayoutChildren = '';
    public static string $html = '';

    /** @var Set<string>|null */
    private static ?Set $headScripts = null;
    /** @var Set<string>|null */
    private static ?Set $footerScripts = null;
    private static array $customMetadata = [];

    private static array $processedScripts = [];

    private const SYSTEM_PROPS = [
        'children' => true,
        'key' => true,
        'ref' => true,
        'pp-context' => true,
        'pp-component' => true,
        'type' => true,
    ];

    public static function init(): void
    {
        if (self::$headScripts === null) {
            self::$headScripts = new Set();
        }
        if (self::$footerScripts === null) {
            self::$footerScripts = new Set();
        }
        self::$processedScripts = [];
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
            self::$headScripts->add($script);
        }
    }

    /**
     * Adds one or more scripts to the footer section if they are not already present.
     *
     * @param string ...$scripts One or more scripts to be added to the footer.
     * @return void
     */
    public static function addFooterScript(string ...$scripts): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $trace[1]['class'] ?? 'Unknown';

        foreach ($scripts as $script) {
            $scriptKey = md5(trim($script));

            if (strpos($script, '<script') !== false) {
                $taggedScript = "<!-- class:" . $callerClass . " -->\n" . $script;
                if (!isset(self::$processedScripts[$scriptKey])) {
                    self::$footerScripts->add($taggedScript);
                    self::$processedScripts[$scriptKey] = true;
                }
            } else {
                if (!isset(self::$processedScripts[$scriptKey])) {
                    self::$footerScripts->add($script);
                    self::$processedScripts[$scriptKey] = true;
                }
            }
        }
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
        $headScriptsArray = self::$headScripts->values();
        $headScriptsWithAttributes = array_map(function ($tag) {
            if (strpos($tag, '<script') !== false) {
                return str_replace('<script', '<script pp-dynamic-script="81D7D"', $tag);
            } elseif (strpos($tag, '<link') !== false) {
                return str_replace('<link', '<link pp-dynamic-link="81D7D"', $tag);
            } elseif (strpos($tag, '<style') !== false) {
                return str_replace('<style', '<style pp-dynamic-style="81D7D"', $tag);
            }
            return $tag;
        }, $headScriptsArray);

        return implode("\n", $headScriptsWithAttributes);
    }

    /**
     * Generates all the footer scripts.
     *
     * @return string The concatenated footer scripts.
     */
    public static function outputFooterScripts(): string
    {
        $processed = [];
        $componentCounter = 0;

        foreach (self::$footerScripts->values() as $script) {
            if (preg_match('/<!-- class:([^\s]+) -->/', $script, $matches)) {
                $rawClassName = $matches[1];
                $script = preg_replace('/<!-- class:[^\s]+ -->\s*/', '', $script, 1);

                if (str_starts_with(trim($script), '<script')) {
                    $script = preg_replace_callback(
                        '/<script\b([^>]*)>/i',
                        function ($m) use ($rawClassName, &$componentCounter) {
                            $attrs = $m[1];
                            $scriptHash = substr(md5($m[0]), 0, 8);
                            $encodedClass = 's' . base_convert(sprintf('%u', crc32($rawClassName . $componentCounter . $scriptHash)), 10, 36);
                            $componentCounter++;

                            $parsedAttrs = self::parseScriptAttributes($attrs);

                            if (!isset($parsedAttrs['pp-component'])) {
                                $parsedAttrs['pp-component'] = $encodedClass;
                            }

                            if (!isset($parsedAttrs['type'])) {
                                $parsedAttrs['type'] = 'text/pp';
                            }

                            $parsedAttrs = self::convertAttributesToKebabCase($parsedAttrs);

                            $newAttrs = self::buildAttributesString($parsedAttrs);

                            return "<script{$newAttrs}>";
                        },
                        $script,
                        1
                    );
                }
            }

            $processed[] = $script;
        }

        return implode("\n", $processed);
    }

    /**
     * Parses script tag attributes into an associative array.
     *
     * @param string $attrString The attributes string from the script tag.
     * @return array The parsed attributes.
     */
    private static function parseScriptAttributes(string $attrString): array
    {
        $attributes = [];
        $attrString = trim($attrString);

        if (empty($attrString)) {
            return $attributes;
        }

        preg_match_all(
            '/(\w[\w:-]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/i',
            $attrString,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = $match[1];
            $value = $match[2] ?? $match[3] ?? $match[4] ?? '';
            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /**
     * Converts camelCase attribute names to kebab-case for component attributes.
     *
     * @param array $attributes The attributes array.
     * @return array The converted attributes.
     */
    private static function convertAttributesToKebabCase(array $attributes): array
    {
        $converted = [];

        foreach ($attributes as $name => $value) {
            $kebabName = self::camelToKebab($name);
            $converted[$kebabName] = $value;
        }

        return $converted;
    }

    /**
     * Converts camelCase string to kebab-case.
     *
     * @param string $string The string to convert.
     * @return string The kebab-case string.
     */
    private static function camelToKebab(string $string): string
    {
        if (isset(self::SYSTEM_PROPS[$string]) || str_contains($string, '-')) {
            return $string;
        }

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $string));
    }

    /**
     * Builds an attributes string from an associative array.
     *
     * @param array $attributes The attributes array.
     * @return string The attributes string.
     */
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
    }

    /**
     * Clears all footer scripts.
     *
     * @return void
     */
    public static function clearFooterScripts(): void
    {
        self::$footerScripts->clear();
        self::$processedScripts = [];
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
