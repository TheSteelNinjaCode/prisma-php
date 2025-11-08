<?php

declare(strict_types=1);

namespace PP\PHPX;

use PP\Validator;
use ReflectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionIntersectionType;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use InvalidArgumentException;
use Exception;

class TypeCoercer
{
    private static array $typeCache = [];
    private static array $phpTypeMap = [
        'boolean' => 'bool',
        'integer' => 'int',
        'double' => 'float',
        'string' => 'string',
        'array' => 'array',
        'object' => 'object',
        'resource' => 'resource',
        'NULL' => 'null',
    ];

    /**
     * Coerce a value to match the target type with strict validation.
     * Always throws exceptions when coercion is not possible.
     *
     * @param mixed $value The value to coerce.
     * @param ReflectionType|null $type The target type.
     * @param array $validationRules Optional validation rules.
     * @return mixed The coerced value.
     * @throws InvalidArgumentException When coercion is not possible.
     */
    public static function coerce(
        mixed $value,
        ?ReflectionType $type,
        array $validationRules = []
    ): mixed {
        if ($type === null) {
            return $value;
        }

        $typeKey = self::getTypeKey($type);
        if (!isset(self::$typeCache[$typeKey])) {
            self::$typeCache[$typeKey] = self::analyzeType($type);
        }

        $typeInfo = self::$typeCache[$typeKey];

        self::validateBeforeCoercion($value, $typeInfo);

        return self::coerceWithTypeInfo($value, $typeInfo, $validationRules);
    }

    private static function validateBeforeCoercion(mixed $value, array $typeInfo): void
    {
        if ($value === null) {
            if (!$typeInfo['allowsNull']) {
                throw new InvalidArgumentException(
                    "Cannot coerce null to non-nullable type"
                );
            }
            return;
        }

        foreach ($typeInfo['types'] as $type) {
            if (self::canCoerceToType($value, $type['name'])) {
                return;
            }
        }

        $expectedTypes = array_map(fn($t) => $t['name'], $typeInfo['types']);
        $expectedType = count($expectedTypes) > 1
            ? implode('|', $expectedTypes)
            : $expectedTypes[0];

        if ($typeInfo['allowsNull']) {
            $expectedType .= '|null';
        }

        throw new InvalidArgumentException(
            sprintf(
                "Cannot coerce %s to %s",
                self::describeValue($value),
                $expectedType
            )
        );
    }

    private static function canCoerceToType(mixed $value, string $typeName): bool
    {
        if (self::valueMatchesTypeName($value, $typeName)) {
            return true;
        }

        return match ($typeName) {
            'bool' => self::canCoerceToBool($value),
            'int' => self::canCoerceToInt($value),
            'float' => self::canCoerceToFloat($value),
            'string' => true,
            'array' => self::canCoerceToArray($value),
            'object' => is_array($value) || self::isJsonString($value),
            'DateTime', 'DateTimeImmutable', 'DateTimeInterface' =>
            self::canCoerceToDateTime($value),
            'BigDecimal' => is_numeric($value) ||
                (is_string($value) && is_numeric(trim($value))),
            'BigInteger' => self::canCoerceToInt($value),
            'mixed' => true,
            default => false,
        };
    }

    private static function canCoerceToBool(mixed $value): bool
    {
        if (is_bool($value) || is_int($value)) {
            return true;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, [
                'true',
                'false',
                '1',
                '0',
                'yes',
                'no',
                'on',
                'off',
                'checked',
                ''
            ], true);
        }

        return false;
    }

    private static function canCoerceToInt(mixed $value): bool
    {
        if (is_int($value) || is_bool($value)) {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return false;
            }

            if (!is_numeric($trimmed)) {
                return false;
            }

            return (string)(int)$trimmed === $trimmed;
        }

        return false;
    }

    private static function canCoerceToFloat(mixed $value): bool
    {
        if (is_float($value) || is_int($value)) {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' && is_numeric($trimmed);
        }

        return false;
    }

    private static function canCoerceToArray(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return true;
            }

            if ($trimmed[0] === '[' || $trimmed[0] === '{') {
                return self::isJsonString($trimmed);
            }

            return str_contains($trimmed, ',');
        }

        return false;
    }

    private static function canCoerceToDateTime(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        if (is_string($value) || is_numeric($value)) {
            try {
                new DateTime((string)$value);
                return true;
            } catch (Exception) {
                return false;
            }
        }

        return false;
    }

    private static function isJsonString(string $value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private static function valueMatchesTypeName(mixed $value, string $typeName): bool
    {
        return match ($typeName) {
            'bool' => is_bool($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'mixed' => true,
            'DateTime' => $value instanceof DateTime,
            'DateTimeImmutable' => $value instanceof DateTimeImmutable,
            'DateTimeInterface' => $value instanceof DateTimeInterface,
            'BigDecimal' => $value instanceof BigDecimal,
            'BigInteger' => $value instanceof BigInteger,
            default => $value instanceof $typeName,
        };
    }

    private static function describeValue(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'" . (strlen($value) > 40
                ? substr($value, 0, 37) . '...'
                : $value) . "'",
            is_int($value) => "int({$value})",
            is_float($value) => "float({$value})",
            is_array($value) => 'array[' . count($value) . ']',
            is_object($value) => get_class($value),
            default => gettype($value),
        };
    }

    private static function analyzeType(ReflectionType $type): array
    {
        $info = [
            'isUnion' => false,
            'isIntersection' => false,
            'allowsNull' => false,
            'types' => [],
        ];

        if ($type instanceof ReflectionUnionType) {
            $info['isUnion'] = true;
            foreach ($type->getTypes() as $unionType) {
                if ($unionType->getName() === 'null') {
                    $info['allowsNull'] = true;
                } else {
                    $info['types'][] = [
                        'name' => $unionType->getName(),
                        'isBuiltin' => $unionType->isBuiltin(),
                        'allowsNull' => $unionType->allowsNull(),
                    ];
                }
            }
        } elseif ($type instanceof ReflectionNamedType) {
            $info['allowsNull'] = $type->allowsNull();
            if ($type->getName() !== 'null') {
                $info['types'][] = [
                    'name' => $type->getName(),
                    'isBuiltin' => $type->isBuiltin(),
                    'allowsNull' => $type->allowsNull(),
                ];
            }
        } elseif ($type instanceof ReflectionIntersectionType) {
            $info['isIntersection'] = true;
            foreach ($type->getTypes() as $intersectionType) {
                if ($intersectionType instanceof ReflectionNamedType) {
                    $info['types'][] = [
                        'name' => $intersectionType->getName(),
                        'isBuiltin' => $intersectionType->isBuiltin(),
                        'allowsNull' => $intersectionType->allowsNull(),
                    ];
                } else {
                    $info['types'][] = [
                        'name' => (string) $intersectionType,
                        'isBuiltin' => false,
                        'allowsNull' => false,
                    ];
                }
            }
        }

        return $info;
    }

    private static function coerceWithTypeInfo(
        mixed $value,
        array $typeInfo,
        array $validationRules = []
    ): mixed {
        if ($value === null && $typeInfo['allowsNull']) {
            return null;
        }

        if ($typeInfo['isUnion']) {
            return self::coerceUnionTypeSmart($value, $typeInfo['types'], $validationRules);
        }

        if (count($typeInfo['types']) === 1) {
            $currentType = self::getNormalizedType($value);
            $targetType = $typeInfo['types'][0]['name'];

            if ($currentType === $targetType) {
                return $value;
            }

            return self::coerceSingleType($value, $typeInfo['types'][0], $validationRules);
        }

        return $value;
    }

    private static function coerceUnionTypeSmart(
        mixed $value,
        array $types,
        array $validationRules = []
    ): mixed {
        $typeNames = array_column($types, 'name');

        return match (true) {
            self::hasTypes($typeNames, ['string', 'bool']) =>
            self::coerceStringBoolUnion($value, $types, $validationRules),
            self::hasTypes($typeNames, ['int', 'string']) =>
            self::coerceIntStringUnion($value, $types, $validationRules),
            self::hasTypes($typeNames, ['float', 'string']) =>
            self::coerceFloatStringUnion($value, $types, $validationRules),
            self::hasTypes($typeNames, ['array', 'string']) =>
            self::coerceArrayStringUnion($value, $types, $validationRules),
            self::hasDateTimeTypes($typeNames) =>
            self::coerceDateTimeUnion($value, $types, $validationRules),
            default => self::coerceUnionTypePhpCompliant($value, $types, $validationRules)
        };
    }

    private static function coerceStringBoolUnion(
        mixed $value,
        array $types,
        array $validationRules = []
    ): mixed {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && self::isBooleanLike($value)) {
            return self::coerceBool($value, $validationRules);
        }
        return self::coerceString($value, $validationRules);
    }

    private static function coerceIntStringUnion(
        mixed $value,
        array $types,
        array $validationRules = []
    ): mixed {
        if (is_int($value)) {
            return $value;
        }
        if (self::isIntegerLike($value)) {
            return self::coerceInt($value, $validationRules);
        }
        return self::coerceString($value, $validationRules);
    }

    private static function coerceFloatStringUnion(
        mixed $value,
        array $types,
        array $validationRules = []
    ): mixed {
        if (is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return self::coerceFloat($value, $validationRules);
        }
        return self::coerceString($value, $validationRules);
    }

    private static function coerceArrayStringUnion(
        mixed $value,
        array $types,
        array $validationRules = []
    ): mixed {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && self::isArrayLike($value)) {
            return self::coerceArray($value, $validationRules);
        }
        return self::coerceString($value, $validationRules);
    }

    private static function coerceDateTimeUnion(
        mixed $value,
        array $types,
        array $validationRules = []
    ): mixed {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        if (is_string($value) || is_numeric($value)) {
            foreach ($types as $typeInfo) {
                if (in_array($typeInfo['name'], ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'])) {
                    $coerced = self::coerceCustomType($value, $typeInfo['name'], $validationRules);
                    if ($coerced !== $value) {
                        return $coerced;
                    }
                }
            }
        }
        return self::coerceString($value, $validationRules);
    }

    private static function coerceUnionTypePhpCompliant(
        mixed $value,
        array $types,
        array $validationRules = []
    ): mixed {
        foreach ($types as $typeInfo) {
            $coerced = self::coerceSingleType($value, $typeInfo, $validationRules);
            if (self::isValidCoercion($value, $coerced, $typeInfo['name'])) {
                return $coerced;
            }
        }
        return $value;
    }

    private static function hasTypes(array $typeNames, array $requiredTypes): bool
    {
        foreach ($requiredTypes as $required) {
            if (!in_array($required, $typeNames, true)) {
                return false;
            }
        }
        return true;
    }

    private static function hasDateTimeTypes(array $typeNames): bool
    {
        $dateTimeTypes = ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'];
        return !empty(array_intersect($typeNames, $dateTimeTypes));
    }

    private static function isBooleanLike(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim($value)), [
            'true',
            'false',
            '1',
            '0',
            'yes',
            'no',
            'on',
            'off',
            'checked',
            ''
        ], true);
    }

    private static function isIntegerLike(mixed $value): bool
    {
        return is_string($value) &&
            is_numeric($value) &&
            (string)(int)$value === trim($value);
    }

    private static function isArrayLike(string $value): bool
    {
        return Validator::json($value) === true
            || str_contains($value, ',')
            || str_contains($value, '[')
            || str_contains($value, '{');
    }

    private static function getNormalizedType(mixed $value): string
    {
        $type = gettype($value);
        return self::$phpTypeMap[$type] ?? $type;
    }

    private static function coerceSingleType(
        mixed $value,
        array $typeInfo,
        array $validationRules = []
    ): mixed {
        if (!$typeInfo['isBuiltin']) {
            return self::coerceCustomType($value, $typeInfo['name'], $validationRules);
        }

        return match ($typeInfo['name']) {
            'bool' => self::coerceBool($value, $validationRules),
            'int' => self::coerceInt($value, $validationRules),
            'float' => self::coerceFloat($value, $validationRules),
            'string' => self::coerceString($value, $validationRules),
            'array' => self::coerceArray($value, $validationRules),
            'object' => self::coerceObject($value, $validationRules),
            'mixed' => $value,
            default => $value,
        };
    }

    private static function coerceBool(mixed $value, array $rules = []): bool
    {
        $validated = Validator::boolean($value);
        if ($validated !== null) {
            return $validated;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'yes', 'on', 'checked' => true,
                'false', '0', 'no', 'off', '' => false,
                default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            };
        }

        return (bool) $value;
    }

    private static function coerceInt(mixed $value, array $rules = []): int
    {
        $validated = Validator::int($value);
        return $validated ?? (int) $value;
    }

    private static function coerceFloat(mixed $value, array $rules = []): float
    {
        $validated = Validator::float($value);
        return $validated ?? (float) $value;
    }

    private static function coerceString(mixed $value, array $rules = []): string
    {
        $escapeHtml = $rules['escapeHtml'] ?? true;

        if (isset($rules['type'])) {
            return match ($rules['type']) {
                'email' => Validator::email($value) ?? Validator::string($value, $escapeHtml),
                'url' => Validator::url($value) ?? Validator::string($value, $escapeHtml),
                'uuid' => Validator::uuid($value) ?? Validator::string($value, $escapeHtml),
                'ulid' => Validator::ulid($value) ?? Validator::string($value, $escapeHtml),
                'cuid' => Validator::cuid($value) ?? Validator::string($value, $escapeHtml),
                'cuid2' => Validator::cuid2($value) ?? Validator::string($value, $escapeHtml),
                'ip' => Validator::ip($value) ?? Validator::string($value, $escapeHtml),
                'emojis' => Validator::emojis(Validator::string($value, $escapeHtml)),
                default => Validator::string($value, $escapeHtml),
            };
        }

        return Validator::string($value, $escapeHtml);
    }

    private static function coerceArray(mixed $value, array $rules = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (Validator::json($value) === true) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            if (str_contains($value, ',')) {
                return array_map('trim', explode(',', $value));
            }

            return [$value];
        }

        return (array) $value;
    }

    private static function coerceObject(mixed $value, array $rules = []): object
    {
        if (is_object($value)) {
            return $value;
        }

        if (is_array($value)) {
            return (object) $value;
        }

        if (is_string($value) && Validator::json($value)) {
            $decoded = json_decode($value);
            if (is_object($decoded)) {
                return $decoded;
            }
        }

        return (object) $value;
    }

    private static function coerceCustomType(
        mixed $value,
        string $typeName,
        array $rules = []
    ): mixed {
        return match ($typeName) {
            'DateTime' => self::coerceDateTime($value, $rules),
            'DateTimeImmutable' => self::coerceDateTimeImmutable($value, $rules),
            'DateTimeInterface' => self::coerceDateTimeInterface($value, $rules),
            'BigDecimal' => self::coerceBigDecimal($value, $rules),
            'BigInteger' => self::coerceBigInteger($value, $rules),
            default => $value,
        };
    }

    private static function coerceDateTime(mixed $value, array $rules = []): mixed
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if ($value instanceof DateTimeImmutable) {
            return DateTime::createFromImmutable($value);
        }

        $format = $rules['format'] ?? null;

        try {
            if ($format) {
                return DateTime::createFromFormat($format, (string)$value) ?: $value;
            }
            return new DateTime((string)$value);
        } catch (Exception) {
            return $value;
        }
    }

    private static function coerceDateTimeImmutable(mixed $value, array $rules = []): mixed
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTime) {
            return DateTimeImmutable::createFromMutable($value);
        }

        $format = $rules['format'] ?? null;

        try {
            if ($format) {
                return DateTimeImmutable::createFromFormat($format, (string)$value) ?: $value;
            }
            return new DateTimeImmutable((string)$value);
        } catch (Exception) {
            return $value;
        }
    }

    private static function coerceDateTimeInterface(mixed $value, array $rules = []): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        return self::coerceDateTimeImmutable($value, $rules);
    }

    private static function coerceBigDecimal(mixed $value, array $rules = []): mixed
    {
        $scale = $rules['scale'] ?? 30;
        return Validator::decimal($value, $scale) ?? $value;
    }

    private static function coerceBigInteger(mixed $value, array $rules = []): mixed
    {
        return Validator::bigInt($value) ?? $value;
    }

    private static function isValidCoercion(
        mixed $original,
        mixed $coerced,
        string $typeName
    ): bool {
        if (gettype($original) === gettype($coerced)) {
            return match ($typeName) {
                'string' => true,
                'array' => $original !== $coerced,
                default => $original === $coerced,
            };
        }

        return match ($typeName) {
            'bool' => is_bool($coerced),
            'int' => is_int($coerced),
            'float' => is_float($coerced),
            'string' => is_string($coerced),
            'array' => is_array($coerced),
            'object' => is_object($coerced),
            'DateTime' => $coerced instanceof DateTime,
            'DateTimeImmutable' => $coerced instanceof DateTimeImmutable,
            'DateTimeInterface' => $coerced instanceof DateTimeInterface,
            'BigDecimal' => $coerced instanceof BigDecimal,
            'BigInteger' => $coerced instanceof BigInteger,
            default => true,
        };
    }

    private static function getTypeKey(ReflectionType $type): string
    {
        if ($type instanceof ReflectionUnionType) {
            $types = array_map(fn($t) => $t->getName(), $type->getTypes());
            sort($types);
            return 'union:' . implode('|', $types);
        }

        if ($type instanceof ReflectionNamedType) {
            return 'named:' . $type->getName() . ($type->allowsNull() ? '|null' : '');
        }

        if ($type instanceof ReflectionIntersectionType) {
            $types = array_map(function ($t) {
                return $t instanceof ReflectionNamedType ? $t->getName() : (string) $t;
            }, $type->getTypes());
            sort($types);
            return 'intersection:' . implode('&', $types);
        }

        return 'unknown:' . get_class($type);
    }

    public static function clearCache(): void
    {
        self::$typeCache = [];
    }

    public static function getCacheStats(): array
    {
        return [
            'type_cache_size' => count(self::$typeCache),
            'cached_types' => array_keys(self::$typeCache),
        ];
    }
}
