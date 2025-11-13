<?php

declare(strict_types=1);

namespace PP\PHPX;

use TailwindMerge\TailwindMerge;

class TwMerge
{
    /**
     * Merges Tailwind CSS class names, resolving conflicts.
     *
     * @param string|array ...$inputs One or more strings or arrays of class names.
     * @return string The merged class names.
     */
    public static function merge(string|array ...$inputs): string
    {
        $tw = TailwindMerge::instance();

        $allClasses = [];

        foreach ($inputs as $input) {
            if (is_array($input)) {
                $allClasses[] = implode(' ', array_filter($input));
            } else {
                $allClasses[] = trim($input);
            }
        }

        $classString = trim(implode(' ', array_filter($allClasses)));

        if ($classString === '') {
            return '';
        }

        return $tw->merge($classString);
    }
}
