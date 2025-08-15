<?php

declare(strict_types=1);

namespace PPHP\PHPX;

use PPHP\PHPX\IPHPX;
use PPHP\PHPX\TwMerge;
use PPHP\PrismaPHPSettings;
use Exception;

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
     * @var array|null Component hierarchy set during rendering
     */
    protected static ?array $currentHierarchy = null;

    /**
     * @var string|null Current component ID
     */
    protected static ?string $currentComponentId = null;

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
     * Convert a property name to a JavaScript variable path.
     *
     * This method generates a JavaScript variable path for the given property name,
     * based on the current component hierarchy. It is used to access properties in
     * the JavaScript context of the component.
     *
     * @param string $name The property name to convert.
     * @return string The JavaScript variable path for the property.
     */
    protected function propsAsVar(string $name): string
    {
        if (empty($name)) {
            return '';
        }

        $hierarchy = $this->getComponentHierarchy();

        if (count($hierarchy) > 1 && end($hierarchy) === self::$currentComponentId) {
            array_pop($hierarchy);
        }

        return "pphp.props." . implode('.', $hierarchy) . ".{$name}";
    }

    /**
     * Emit a client-side dispatch call.
     *
     * @param non-empty-string|null $name  Reactive key (e.g. "myVar", "voucher.total", or "app.s9ggniz.myVar").
     * @param string                $value Raw JS inserted verbatim (variable, expression, or pre-JSON-encoded value).
     * @param array{
     *   scope?: 'current'|'parent'|'root'|string[]
     * }                              $options Dispatch options (defaults to ['scope' => 'current']).
     *
     * @return string JavaScript snippet like: pphp.dispatchEvent("myVar", 123, {"scope":"current"});
     */
    protected function dispatchEvent(?string $name, string $value, array $options = []): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        // normalize options
        $opts = $options;
        if (!array_key_exists('scope', $opts)) {
            $opts['scope'] = 'current';
        } else {
            if (is_string($opts['scope'])) {
                $allowed = ['current', 'parent', 'root'];
                if (!in_array($opts['scope'], $allowed, true)) {
                    $opts['scope'] = 'current';
                }
            } elseif (is_array($opts['scope'])) {
                $opts['scope'] = array_values(array_map('strval', $opts['scope']));
            } else {
                $opts['scope'] = 'current';
            }
        }

        // safely encode name/options; value is inserted verbatim
        $jsName = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jsOpts = json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "pphp.dispatchEvent({$jsName}, {$value}, {$jsOpts});";
    }

    /**
     * Set the component hierarchy and current component ID (called by TemplateCompiler during rendering)
     *
     * @param array $hierarchy The component hierarchy
     * @param string $currentId The current component ID
     */
    public static function setRenderingContext(array $hierarchy, string $currentId): void
    {
        self::$currentHierarchy = $hierarchy;
        self::$currentComponentId = $currentId;
    }

    /**
     * Get the parent component ID from the current hierarchy.
     *
     * @return string|null The parent component ID, or null if no parent exists
     */
    protected function getParentComponent(): ?string
    {
        $fullHierarchy = $this->getComponentHierarchy();

        if (count($fullHierarchy) >= 2) {
            return $fullHierarchy[count($fullHierarchy) - 2];
        }

        return null;
    }

    /**
     * Get the full component hierarchy as an array.
     * Useful for building complete hierarchy paths for JavaScript.
     *
     * @return array Array of component IDs from root to current
     */
    protected function getComponentHierarchy(): array
    {
        return self::$currentHierarchy ?? ['app'];
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
