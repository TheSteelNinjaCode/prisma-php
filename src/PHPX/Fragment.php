<?php

declare(strict_types=1);

namespace PP\PHPX;

use PP\PHPX\PHPX;

class Fragment extends PHPX
{
    /** @property ?string $as = div|span|section|article|nav|header|footer|main|aside */
    public ?string $as = null;
    public ?string $class = '';
    public mixed $children = null;

    public function __construct(array $props = [])
    {
        parent::__construct($props);
    }

    public function render(): string
    {
        if ($this->as !== null) {
            $attributes = $this->getAttributes();
            $class = $this->getMergeClasses($this->class);
            $classAttr = $class ? "class=\"{$class}\"" : '';

            return "<{$this->as} {$classAttr} {$attributes}>{$this->children}</{$this->as}>";
        }

        return $this->children;
    }
}
