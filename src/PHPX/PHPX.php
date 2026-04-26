<?php

declare(strict_types=1);

namespace PP\PHPX;

use PP\PHPX\IPHPX;
use PP\PHPX\TwMerge;
use PP\PrismaPHPSettings;
use Exception;
use DateTime;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionProperty;
use PP\PHPX\TypeCoercer;
use InvalidArgumentException;

class PHPX implements IPHPX
{
    /**
     * @var array<string, mixed> The properties or attributes passed to the component.
     */
    protected array $props;

    /**
     * @var mixed The children elements or content to be rendered within the component.
     */
    protected mixed $children;

    /**
     * @var array<string, mixed> The array representation of the HTML attributes.
     */
    protected array $attributesArray = [];

    /**
     * @var array<string, true>
     */
    protected array $attributePropExclusions = [];

    /**
     * @var array<string, true>
     */
    protected array $incomingPropSerializationExclusions = [];

    /**
     * @var array<class-string, array<string, \ReflectionType|null>>
     */
    private static array $publicPropertyTypeCache = [];

    /**
     * @var array<class-string, array<string, array<string, mixed>|null>>
     */
    private static array $publicPropertyTypeInfoCache = [];

    /**
     * @var array<class-string, array<string, string>>
     */
    private static array $publicPropertyLookupCache = [];

    /**
     * @var array<string, string>
     */
    private static array $mergedClassCache = [];

    private const MERGED_CLASS_CACHE_LIMIT = 2048;

    /**
     * Constructor to initialize the component with the given properties.
     *
     * @param array<string, mixed> $props Optional properties to customize the component.
     */
    public function __construct(array $props = [])
    {
        $className = static::class;
        $propertyTypes = self::getPublicPropertyTypes($className);
        $propertyTypeInfos = self::getPublicPropertyTypeInfos($className, $propertyTypes);
        $propertyLookup = self::getPublicPropertyLookup($className, $propertyTypes);
        $normalizedProps = [];

        foreach ($props as $key => $value) {
            $originalKey = (string) $key;
            $normalizedKey = self::resolvePublicPropertyName($originalKey, $propertyTypes, $propertyLookup) ?? $originalKey;

            if (!array_key_exists($normalizedKey, $normalizedProps) || $normalizedKey === $originalKey) {
                $normalizedProps[$normalizedKey] = $value;
            }

            if (!array_key_exists($normalizedKey, $propertyTypes)) {
                continue;
            }

            if ($originalKey !== $normalizedKey) {
                $this->attributePropExclusions[$normalizedKey] = true;
                $this->incomingPropSerializationExclusions[$originalKey] = true;
            }

            $valueForCoercion = self::normalizeValuelessBooleanProp($value, $propertyTypeInfos[$normalizedKey] ?? null);

            try {
                $coercedValue = TypeCoercer::coerceWithCachedTypeInfo(
                    $valueForCoercion,
                    $propertyTypes[$normalizedKey],
                    $propertyTypeInfos[$normalizedKey] ?? null
                );
                $this->$normalizedKey = $coercedValue;
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Invalid value for property '%s' in %s: %s",
                        $normalizedKey,
                        $className,
                        $e->getMessage()
                    )
                );
            }
        }

        $this->props = $normalizedProps;
        $this->children = $normalizedProps['children'] ?? '';
    }

    /**
     * @return array<string, \ReflectionType|null>
     */
    private static function getPublicPropertyTypes(string $className): array
    {
        if (!isset(self::$publicPropertyTypeCache[$className])) {
            $reflection = new ReflectionClass($className);
            $propertyTypes = [];

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $propertyTypes[$property->getName()] = $property->getType();
            }

            self::$publicPropertyTypeCache[$className] = $propertyTypes;
        }

        return self::$publicPropertyTypeCache[$className];
    }

    /**
     * @param array<string, \ReflectionType|null> $propertyTypes
     * @return array<string, array<string, mixed>|null>
     */
    private static function getPublicPropertyTypeInfos(string $className, array $propertyTypes): array
    {
        if (!isset(self::$publicPropertyTypeInfoCache[$className])) {
            self::$publicPropertyTypeInfoCache[$className] = array_map(
                static fn($type) => TypeCoercer::getCachedTypeInfo($type),
                $propertyTypes
            );
        }

        return self::$publicPropertyTypeInfoCache[$className];
    }

    /**
     * @param array<string, \ReflectionType|null> $propertyTypes
     * @return array<string, string>
     */
    private static function getPublicPropertyLookup(string $className, array $propertyTypes): array
    {
        if (!isset(self::$publicPropertyLookupCache[$className])) {
            $lookup = [];

            foreach (array_keys($propertyTypes) as $propertyName) {
                $lookup[strtolower($propertyName)] = $propertyName;

                $kebabCase = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $propertyName));
                $lookup[$kebabCase] = $propertyName;
            }

            self::$publicPropertyLookupCache[$className] = $lookup;
        }

        return self::$publicPropertyLookupCache[$className];
    }

    /**
     * @param array<string, \ReflectionType|null> $propertyTypes
     * @param array<string, string> $propertyLookup
     */
    private static function resolvePublicPropertyName(string $key, array $propertyTypes, array $propertyLookup): ?string
    {
        if (array_key_exists($key, $propertyTypes)) {
            return $key;
        }

        $normalizedKey = strtolower($key);
        return $propertyLookup[$normalizedKey] ?? null;
    }

    /**
     * @param array<string, mixed>|null $typeInfo
     */
    private static function normalizeValuelessBooleanProp(mixed $value, ?array $typeInfo): mixed
    {
        if ($value !== '' || $typeInfo === null) {
            return $value;
        }

        foreach ($typeInfo['types'] ?? [] as $type) {
            if (($type['name'] ?? null) === 'bool') {
                return true;
            }
        }

        return $value;
    }

    /**
     * Converts a PHP value to a JavaScript-compatible representation.
     *
     * This method handles various data types including booleans, nulls, numbers,
     * strings, and objects like DateTime. It can return either raw PHP values or
     * string representations suitable for embedding in JavaScript code.
     *
     * @param mixed $value The PHP value to be converted.
     * @param array{
     *     asString?: bool,    // Whether to return as string representation. Default is true.
     *     prettyPrint?: bool, // Whether to format JSON output for readability. Default is false.
     *     in_attr?: bool      // Whether the output will be used in an HTML attribute. Default is true.
     * } $options Optional settings for conversion.
     * @return mixed|string Raw value if asString is false, otherwise string representation.
     */
    public function toRaw(mixed $value, array $options = []): mixed
    {
        $asString    = $options['asString'] ?? true;
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

        $processedValue = match (true) {
            is_bool($value) => $asString ? ($value ? 'true' : 'false') : $value,
            is_null($value) => $asString ? 'null' : null,
            is_int($value) || is_float($value) => $asString ? (string) $value : $value,

            is_string($value) => (function () use ($value, $asString, $inAttr, $flags, $isSingleMustache) {
                $trim = trim($value);

                if ($isSingleMustache($trim)) {
                    $content = substr($trim, 1, -1);

                    if ($content === 'true') return $asString ? 'true' : true;
                    if ($content === 'false') return $asString ? 'false' : false;
                    if ($content === 'null') return $asString ? 'null' : null;

                    if (is_numeric($content)) {
                        if ($asString) {
                            return $content;
                        }
                        return str_contains($content, '.') ? (float)$content : (int)$content;
                    }

                    return $content;
                }

                if (!$asString) {
                    return $value;
                }

                if ($inAttr) {
                    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }

                return json_encode($value, $flags) ?: '""';
            })(),

            $value instanceof DateTime || $value instanceof DateTimeImmutable =>
            $asString ? json_encode($value->format('c'), $flags) : $value->format('c'),

            default => $asString ? (json_encode($value, $flags) ?: 'null') : $value,
        };

        return $processedValue;
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
        $tailwindEnabled = PrismaPHPSettings::$option->tailwindcss;
        $cacheKey = self::buildMergedClassCacheKey($classes, $tailwindEnabled);

        if (isset(self::$mergedClassCache[$cacheKey])) {
            return self::$mergedClassCache[$cacheKey];
        }

        $all = array_merge($classes);

        $expr = [];
        foreach ($all as &$chunk) {
            $chunk = preg_replace_callback(
                '/\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/',
                function ($m) use (&$expr) {
                    $token = '__EXPR' . count($expr) . '__';
                    $expr[$token] = $m[0];
                    return $token;
                },
                $chunk
            );
        }
        unset($chunk);

        $merged = $tailwindEnabled
            ? TwMerge::merge(...$all)
            : $this->mergeClasses(...$all);

        $result = str_replace(array_keys($expr), array_values($expr), $merged);

        if (count(self::$mergedClassCache) >= self::MERGED_CLASS_CACHE_LIMIT) {
            self::$mergedClassCache = [];
        }

        self::$mergedClassCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param array<int, string|array> $classes
     */
    private static function buildMergedClassCacheKey(array $classes, bool $tailwindEnabled): string
    {
        return ($tailwindEnabled ? '1:' : '0:') . md5(serialize($classes));
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
        $reserved = [
            'class',
            'children',
            ...array_keys($this->attributePropExclusions),
        ];
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
     * @param array<string, mixed> $incomingProps
     * @return array<string, mixed>
     */
    public function filterIncomingPropsForRootSerialization(array $incomingProps): array
    {
        if ($this->incomingPropSerializationExclusions === []) {
            return $incomingProps;
        }

        return array_diff_key($incomingProps, $this->incomingPropSerializationExclusions);
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
