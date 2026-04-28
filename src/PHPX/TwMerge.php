<?php

declare(strict_types=1);

namespace PP\PHPX;

class TwMerge
{
    private const MUSTACHE_PATTERN = '/\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/';

    /**
     * Builds a frontend twMerge(...) expression that PulsePoint evaluates on the client.
     *
     * @param string|array ...$inputs One or more strings or arrays of class names.
     * @return string A pure binding expression when inputs exist, otherwise an empty string.
     */
    public static function merge(string|array ...$inputs): string
    {
        $arguments = [];

        foreach ($inputs as $input) {
            foreach (self::flattenInput($input) as $chunk) {
                $normalizedChunk = trim($chunk);

                if ($normalizedChunk === '') {
                    continue;
                }

                $arguments[] = self::convertChunkToJavascriptExpression($normalizedChunk);
            }
        }

        if ($arguments === []) {
            return '';
        }

        return '{twMerge(' . implode(', ', $arguments) . ')}';
    }

    /**
     * @return list<string>
     */
    private static function flattenInput(string|array $input): array
    {
        if (!is_array($input)) {
            return [(string) $input];
        }

        $flattened = [];

        array_walk_recursive($input, static function ($value) use (&$flattened): void {
            if ($value === null) {
                return;
            }

            $flattened[] = (string) $value;
        });

        return $flattened;
    }

    private static function convertChunkToJavascriptExpression(string $chunk): string
    {
        if (self::isPureMustacheExpression($chunk)) {
            return self::unwrapMustacheExpression($chunk);
        }

        if (!str_contains($chunk, '{')) {
            return self::toJavascriptStringLiteral($chunk);
        }

        preg_match_all(self::MUSTACHE_PATTERN, $chunk, $matches, PREG_OFFSET_CAPTURE);

        $parts = [];
        $offset = 0;

        foreach ($matches[0] as [$match, $position]) {
            $literal = substr($chunk, $offset, $position - $offset);
            if ($literal !== '') {
                $parts[] = self::toJavascriptStringLiteral($literal);
            }

            $expression = self::unwrapMustacheExpression($match);
            if ($expression !== '') {
                $parts[] = '(' . $expression . ')';
            }

            $offset = $position + strlen($match);
        }

        $tail = substr($chunk, $offset);
        if ($tail !== '') {
            $parts[] = self::toJavascriptStringLiteral($tail);
        }

        if ($parts === []) {
            return self::toJavascriptStringLiteral('');
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return implode(' . ', $parts);
    }

    private static function isPureMustacheExpression(string $chunk): bool
    {
        return preg_match('/^\s*\{[\s\S]*\}\s*$/', $chunk) === 1;
    }

    private static function unwrapMustacheExpression(string $chunk): string
    {
        return trim(substr(trim($chunk), 1, -1));
    }

    private static function toJavascriptStringLiteral(string $value): string
    {
        $escaped = str_replace(
            ["\\", "'", "\r", "\n"],
            ["\\\\", "\\'", "\\r", "\\n"],
            $value
        );

        return "'{$escaped}'";
    }
}
