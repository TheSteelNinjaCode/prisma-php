<?php

declare(strict_types=1);

namespace PP\PHPX;

use PP\PHPX\IPHPX;
use PP\PHPX\TwMerge;
use PP\PrismaPHPSettings;
use Exception;
use DateTime;
use DateTimeImmutable;

class PHPX implements IPHPX
{
    /**
     * @var array<string, mixed> The properties or attributes passed to the component.
     */
    protected array $props;

    /**
     * @var mixed The children elements or content to be rendered within the component.
     */
    public mixed $children;

    /**
     * @var array<string, mixed> The array representation of the HTML attributes.
     */
    protected array $attributesArray = [];

    /**
     * Constructor to initialize the component with the given properties.
     *
     * @param array<string, mixed> $props Optional properties to customize the component.
     */
    public function __construct(array $props = [])
    {
        $this->props = $props;
        $this->children = $props['children'] ?? '';
    }

    /**
     * Converts a PHP value to a JavaScript-compatible string representation.
     *
     * This method handles various data types including booleans, nulls, numbers,
     * strings, and objects like DateTime. It ensures that the output is suitable
     * for embedding in JavaScript code, particularly within HTML attributes or scripts.
     *
     * @param mixed $value The PHP value to be converted.
     * @param array{
     *     prettyPrint?: bool, // Whether to format the JSON output for readability. Default is false.
     *     in_attr?: bool      // Whether the output will be used in an HTML attribute. Default is true.
     * } $options Optional settings for conversion.
     * @return string A string representation of the value suitable for JavaScript.
     */
    public function toJs(mixed $value, array $options = []): string
    {
        $prettyPrint = $options['prettyPrint'] ?? false;
        $inAttr      = $options['in_attr'] ?? true;
        $flags       = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $isSingleMustache = static function (string $s): bool {
            $s = trim($s);
            if ($s === '{}' || $s === '') return false;
            if ($s[0] !== '{' || substr($s, -1) !== '}') return false;
            return true;
        };

        return match (true) {
            is_bool($value) => $value ? '{true}' : '{false}',
            is_null($value) => '{null}',
            is_int($value) || is_float($value) => (string) $value,

            is_string($value) => (function () use ($value, $inAttr, $flags, $isSingleMustache) {
                $trim = trim($value);

                if ($isSingleMustache($trim)) {
                    return $trim;
                }

                if ($inAttr) {
                    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }

                return json_encode($value, $flags) ?: '""';
            })(),

            $value instanceof DateTime || $value instanceof DateTimeImmutable =>
            json_encode($value->format('c'), $flags),

            default => json_encode($value, $flags) ?: '{null}',
        };
    }

    /**
     * Combines and returns the CSS classes for the component.
     *
     * This method merges the provided classes, which can be either strings or arrays of strings,
     * without automatically including the component's `$class` property. It uses the `Utils::mergeClasses`
     * method to ensure that the resulting CSS class string is optimized, with duplicate or conflicting
     * classes removed.
     *
     * ### Features:
     * - Accepts multiple arguments as strings or arrays of strings.
     * - Only merges the classes provided as arguments (does not include `$this->class` automatically).
     * - Ensures the final CSS class string is well-formatted and free of conflicts.
     *
     * @param string|array ...$classes The CSS classes to be merged. Each argument can be a string or an array of strings.
     * @return string A single CSS class string with the merged and optimized classes.
     */
    protected function getMergeClasses(string|array ...$classes): string
    {
        $all = array_merge($classes);

        $expr = [];
        foreach ($all as &$chunk) {
            $chunk = preg_replace_callback('/\{\{[\s\S]*?\}\}/', function ($m) use (&$expr) {
                $token = '__EXPR' . count($expr) . '__';
                $expr[$token] = $m[0];
                return $token;
            }, $chunk);
        }
        unset($chunk);

        $merged = PrismaPHPSettings::$option->tailwindcss
            ? TwMerge::merge(...$all)
            : $this->mergeClasses(...$all);

        return str_replace(array_keys($expr), array_values($expr), $merged);
    }

    /**
     * Merges multiple CSS class strings or arrays of CSS class strings into a single, optimized CSS class string.
     *
     * @param string|array ...$classes The CSS classes to be merged.
     * @return string A single CSS class string with duplicates resolved.
     */
    private function mergeClasses(string|array ...$classes): string
    {
        $classSet = [];

        foreach ($classes as $class) {
            $classList = is_array($class) ? $class : [$class];
            foreach ($classList as $item) {
                if (!empty(trim($item))) {
                    $splitClasses = preg_split("/\s+/", $item);
                    foreach ($splitClasses as $individualClass) {
                        $classSet[$individualClass] = true;
                    }
                }
            }
        }

        return implode(" ", array_keys($classSet));
    }

    /**
     * Build an HTML-attribute string.
     *
     * • Always ignores "class" and "children".  
     * • $params overrides anything in $this->props.  
     * • Pass names in $exclude to drop them for this call.
     *
     * @param array $params  Extra / overriding attributes           (optional)
     * @param array $exclude Attribute names to remove on the fly    (optional)
     * @return string Example: id="btn" data-id="7"
     */
    protected function getAttributes(array $params = [], array $exclude = []): string
    {
        $reserved = ['class', 'children'];
        $props = array_diff_key(
            $this->props,
            array_flip(array_merge($reserved, $exclude))
        );

        $props = array_merge($params, $props);

        $pairs = array_map(
            static fn($k, $v) => sprintf(
                "%s='%s'",
                htmlspecialchars($k, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8')
            ),
            array_keys($props),
            $props
        );

        $this->attributesArray = $props;
        return implode(' ', $pairs);
    }

    /**
     * Renders the component as an HTML string with the appropriate classes and attributes.
     * Also, allows for dynamic children rendering if a callable is passed.
     * 
     * @return string The final rendered HTML of the component.
     */
    public function render(): string
    {
        $attributes = $this->getAttributes();
        $class = $this->getMergeClasses();

        return <<<HTML
        <div class="{$class}" {$attributes}>{$this->children}</div>
        HTML;
    }

    /**
     * Converts the object to its string representation by rendering the component.
     *
     * This method allows the object to be used directly in string contexts, such as
     * when echoing or concatenating, by automatically invoking the `render()` method.
     * If an exception occurs during rendering, it safely returns an empty string
     * to prevent runtime errors, ensuring robustness in all scenarios.
     *
     * @return string The rendered HTML output of the component, or an empty string if rendering fails.
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (Exception) {
            return '';
        }
    }
}
