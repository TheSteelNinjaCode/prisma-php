<?php

declare(strict_types=1);

namespace PPHP;

use RuntimeException;
use InvalidArgumentException;
use PPHP\PHPX\TemplateCompiler;

class IncludeTracker
{
    private static array $sections = [];

    /**
     * Includes and echoes a file wrapped in a unique pp-component container.
     * Supported $mode values: 'include', 'include_once', 'require', 'require_once'
     *
     * @param string $filePath The path to the file to be included.
     * @param string $mode     The mode of inclusion.
     * @throws RuntimeException        If the file does not exist.
     * @throws InvalidArgumentException If an invalid mode is provided.
     * @return void
     */
    public static function render(string $filePath, string $mode = 'include_once'): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }

        ob_start();
        match ($mode) {
            'include'       => include $filePath,
            'include_once'  => include_once $filePath,
            'require'       => require $filePath,
            'require_once'  => require_once $filePath,
            default         => throw new InvalidArgumentException("Invalid include mode: $mode"),
        };
        $html = ob_get_clean();

        $wrapped  = self::wrapWithId($filePath, $html);
        $fragDom  = TemplateCompiler::convertToXml($wrapped);

        $newHtml = TemplateCompiler::innerXml($fragDom);

        self::$sections[$filePath] = [
            'path' => $filePath,
            'html' => $newHtml,
        ];

        echo $newHtml;
    }

    private static function wrapWithId(string $filePath, string $html): string
    {
        $id = 's' . base_convert(sprintf('%u', crc32($filePath)), 10, 36);
        return "<div pp-component=\"$id\">\n$html\n</div>";
    }
}
