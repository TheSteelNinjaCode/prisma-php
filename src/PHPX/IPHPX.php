<?php

declare(strict_types=1);

namespace PP\PHPX;

interface IPHPX
{
    /**
     * Constructor to initialize the component with the given properties.
     * 
     * @param array<string, mixed> $props Optional properties to customize the component.
     */
    public function __construct(array $props = []);

    /**
     * Renders the component with the given properties and children.
     * 
     * @return string The rendered HTML content.
     */
    public function render(): string;
}
