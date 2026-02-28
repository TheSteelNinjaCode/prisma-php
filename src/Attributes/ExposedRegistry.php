<?php

declare(strict_types=1);

namespace PP\Attributes;

final class ExposedRegistry
{
    /** @var array<string,string> map shortName => fullyQualifiedFunctionName */
    private static array $functions = [];

    public static function registerFunction(string $shortName, string $fqn): void
    {
        // last one wins (helps if same name is defined multiple times)
        self::$functions[$shortName] = $fqn;
    }

    public static function resolveFunction(string $shortName): ?string
    {
        return self::$functions[$shortName] ?? null;
    }

    public static function all(): array
    {
        return self::$functions;
    }
}
