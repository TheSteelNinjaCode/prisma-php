<?php

namespace PP\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
class Exposed
{
    public function __construct(
        public bool $requiresAuth = false,
        public ?array $allowedRoles = null
    ) {}
}
