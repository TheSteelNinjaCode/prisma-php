<?php

declare(strict_types=1);

namespace PP;

use RuntimeException;
use InvalidArgumentException;
use PP\PHPX\TemplateCompiler;

class IncludeTracker
{
    private static array $sections = [];

    /**
     * Includes and echoes a file wrapped in a configurable container.
     * Supported $mode values: 'include', 'include_once', 'require', 'require_once'
     *
     * @param string $filePath The path to the file to be included.
     * @param array  $props    Props to pass. Use key 'as' to override the container tag (e.g., ['as' => 'main']).
     * @param string $mode     The mode of inclusion.
     * @throws RuntimeException        If the file does not exist.
     * @throws InvalidArgumentException If an invalid mode is provided.
     * @return void
     */
    public static function render(string $filePath, array $props = [], string $mode = 'include_once'): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }

        $containerTag = $props['as'] ?? 'div';

        $attributeProps = $props;
        unset($attributeProps['as']);

        extract($props, EXTR_SKIP);

        ob_start();
        match ($mode) {
            'include'      => include $filePath,
            'include_once' => include_once $filePath,
            'require'      => require $filePath,
            'require_once' => require_once $filePath,
            default        => throw new InvalidArgumentException("Invalid include mode: $mode"),
        };
        $html = ob_get_clean();

        $wrapped  = self::wrapWithId($filePath, $html, $attributeProps, $containerTag);

        $fragDom  = TemplateCompiler::convertToXml($wrapped);
        $newHtml = TemplateCompiler::innerXml($fragDom);

        self::$sections[$filePath] = [
            'path'  => $filePath,
            'html'  => $newHtml,
            'props' => $props,
        ];

        echo $newHtml;
    }

    private static function wrapWithId(string $filePath, string $html, array $props, string $tagName): string
    {
        $id = 's' . base_convert(sprintf('%u', crc32($filePath)), 10, 36);
        $attributes = self::buildAttributes($props);

        return "<$tagName pp-component=\"$id\"$attributes>\n$html\n</$tagName>";
    }

    private static function buildAttributes(array $props): string
    {
        if (empty($props)) {
            return '';
        }

        $attributes = [];
        foreach ($props as $key => $value) {
            if (TemplateCompiler::containsMustacheSyntax((string)$value)) {
                $key = TemplateCompiler::camelToKebab($key);
            }

            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $attributes[] = "$key=\"$escapedValue\"";
        }

        return ' ' . implode(' ', $attributes);
    }
}
