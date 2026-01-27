<?php

namespace PP\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
class Exposed
{
    /**
     * @param bool $requiresAuth
     * @param array|null $allowedRoles
     * @param string|array|null $limits Explicit rate limits (e.g., "5/minute" or ["5/m", "100/d"])
     */
    public function __construct(
        public bool $requiresAuth = false,
        public ?array $allowedRoles = null,
        public string|array|null $limits = null
    ) {}
}
