<?php

declare(strict_types=1);

namespace PPHP\PHPX;

class TwMerge
{
    private static array $classGroups = [
        // Layout
        'aspect' => ['aspect-auto', 'aspect-square', 'aspect-video', '/^aspect-\[.+\]$/'],
        'container' => ['container'],
        'columns' => ['/^columns-(\d+|auto|\[.+\])$/'],
        'break-after' => ['/^break-after-(auto|avoid|all|avoid-page|page|left|right|column)$/'],
        'break-before' => ['/^break-before-(auto|avoid|all|avoid-page|page|left|right|column)$/'],
        'break-inside' => ['/^break-inside-(auto|avoid|avoid-page|avoid-column)$/'],
        'box-decoration' => ['/^box-decoration-(clone|slice)$/'],
        'box' => ['/^box-(border|content)$/'],
        'display' => ['block', 'inline-block', 'inline', 'flex', 'inline-flex', 'table', 'inline-table', 'table-caption', 'table-cell', 'table-column', 'table-column-group', 'table-footer-group', 'table-header-group', 'table-row-group', 'table-row', 'flow-root', 'grid', 'inline-grid', 'contents', 'list-item', 'hidden'],
        'float' => ['/^float-(right|left|none)$/'],
        'clear' => ['/^clear-(left|right|both|none)$/'],
        'isolation' => ['isolate', 'isolation-auto'],
        'object-fit' => ['/^object-(contain|cover|fill|none|scale-down)$/'],
        'object-position' => ['/^object-(bottom|center|left|left-bottom|left-top|right|right-bottom|right-top|top|\[.+\])$/'],
        'overflow' => ['/^overflow-(auto|hidden|clip|visible|scroll)$/'],
        'overflow-x' => ['/^overflow-x-(auto|hidden|clip|visible|scroll)$/'],
        'overflow-y' => ['/^overflow-y-(auto|hidden|clip|visible|scroll)$/'],
        'overscroll' => ['/^overscroll-(auto|contain|none)$/'],
        'overscroll-x' => ['/^overscroll-x-(auto|contain|none)$/'],
        'overscroll-y' => ['/^overscroll-y-(auto|contain|none)$/'],
        'position' => ['static', 'fixed', 'absolute', 'relative', 'sticky'],
        'inset' => ['/^inset-(\d+(\.\d+)?|auto|\[.+\]|px|full)$/', '/^-inset-(\d+(\.\d+)?|\[.+\])$/'],
        'inset-x' => ['/^inset-x-(\d+(\.\d+)?|auto|\[.+\]|px|full)$/', '/^-inset-x-(\d+(\.\d+)?|\[.+\])$/'],
        'inset-y' => ['/^inset-y-(\d+(\.\d+)?|auto|\[.+\]|px|full)$/', '/^-inset-y-(\d+(\.\d+)?|\[.+\])$/'],
        'top' => ['/^top-(\d+(\.\d+)?|auto|\[.+\]|px|full)$/', '/^-top-(\d+(\.\d+)?|\[.+\])$/'],
        'right' => ['/^right-(\d+(\.\d+)?|auto|\[.+\]|px|full)$/', '/^-right-(\d+(\.\d+)?|\[.+\])$/'],
        'bottom' => ['/^bottom-(\d+(\.\d+)?|auto|\[.+\]|px|full)$/', '/^-bottom-(\d+(\.\d+)?|\[.+\])$/'],
        'left' => ['/^left-(\d+(\.\d+)?|auto|\[.+\]|px|full)$/', '/^-left-(\d+(\.\d+)?|\[.+\])$/'],
        'visibility' => ['visible', 'invisible', 'collapse'],
        'z' => ['/^z-(\d+|auto|\[.+\])$/', '/^-z-(\d+|\[.+\])$/'],

        // Flexbox & Grid
        'flex-basis' => ['/^basis-(\d+(\.\d+)?\/\d+|\d+(\.\d+)?|auto|px|full|\[.+\])$/'],
        'flex-direction' => ['/^flex-(row|row-reverse|col|col-reverse)$/'],
        'flex-wrap' => ['/^flex-(wrap|wrap-reverse|nowrap)$/'],
        'flex' => ['/^flex-(1|auto|initial|none|\[.+\])$/'],
        'flex-grow' => ['/^grow(-0|\[.+\])?$/'],
        'flex-shrink' => ['/^shrink(-0|\[.+\])?$/'],
        'order' => ['/^order-(\d+|first|last|none|\[.+\])$/'],
        'grid-template-columns' => ['/^grid-cols-(\d+|none|subgrid|\[.+\])$/'],
        'grid-column' => ['/^col-(auto|span-(\d+|full)|\[.+\])$/'],
        'grid-column-start' => ['/^col-start-(\d+|auto|\[.+\])$/'],
        'grid-column-end' => ['/^col-end-(\d+|auto|\[.+\])$/'],
        'grid-template-rows' => ['/^grid-rows-(\d+|none|subgrid|\[.+\])$/'],
        'grid-row' => ['/^row-(auto|span-(\d+|full)|\[.+\])$/'],
        'grid-row-start' => ['/^row-start-(\d+|auto|\[.+\])$/'],
        'grid-row-end' => ['/^row-end-(\d+|auto|\[.+\])$/'],
        'grid-auto-flow' => ['/^grid-flow-(row|col|dense|row-dense|col-dense)$/'],
        'grid-auto-columns' => ['/^auto-cols-(auto|min|max|fr|\[.+\])$/'],
        'grid-auto-rows' => ['/^auto-rows-(auto|min|max|fr|\[.+\])$/'],
        'gap' => ['/^gap-(\d+(\.\d+)?|px|\[.+\])$/'],
        'gap-x' => ['/^gap-x-(\d+(\.\d+)?|px|\[.+\])$/'],
        'gap-y' => ['/^gap-y-(\d+(\.\d+)?|px|\[.+\])$/'],
        'justify-content' => ['/^justify-(start|end|center|between|around|evenly)$/'],
        'justify-items' => ['/^justify-items-(start|end|center|stretch)$/'],
        'justify-self' => ['/^justify-self-(auto|start|end|center|stretch)$/'],
        'align-content' => ['/^content-(center|start|end|between|around|evenly|baseline|stretch)$/'],
        'align-items' => ['/^items-(start|end|center|baseline|stretch)$/'],
        'align-self' => ['/^self-(auto|start|end|center|stretch|baseline)$/'],
        'place-content' => ['/^place-content-(center|start|end|between|around|evenly|baseline|stretch)$/'],
        'place-items' => ['/^place-items-(start|end|center|baseline|stretch)$/'],
        'place-self' => ['/^place-self-(auto|start|end|center|stretch)$/'],

        // Spacing
        'p' => ['/^p-(\d+(\.\d+)?|px|\[.+\])$/'],
        'px' => ['/^px-(\d+(\.\d+)?|px|\[.+\])$/'],
        'py' => ['/^py-(\d+(\.\d+)?|px|\[.+\])$/'],
        'pt' => ['/^pt-(\d+(\.\d+)?|px|\[.+\])$/'],
        'pr' => ['/^pr-(\d+(\.\d+)?|px|\[.+\])$/'],
        'pb' => ['/^pb-(\d+(\.\d+)?|px|\[.+\])$/'],
        'pl' => ['/^pl-(\d+(\.\d+)?|px|\[.+\])$/'],
        'm' => ['/^-?m-(\d+(\.\d+)?|px|auto|\[.+\])$/'],
        'mx' => ['/^-?mx-(\d+(\.\d+)?|px|auto|\[.+\])$/'],
        'my' => ['/^-?my-(\d+(\.\d+)?|px|auto|\[.+\])$/'],
        'mt' => ['/^-?mt-(\d+(\.\d+)?|px|auto|\[.+\])$/'],
        'mr' => ['/^-?mr-(\d+(\.\d+)?|px|auto|\[.+\])$/'],
        'mb' => ['/^-?mb-(\d+(\.\d+)?|px|auto|\[.+\])$/'],
        'ml' => ['/^-?ml-(\d+(\.\d+)?|px|auto|\[.+\])$/'],
        'space-x' => ['/^-?space-x-(\d+(\.\d+)?|px|reverse|\[.+\])$/'],
        'space-y' => ['/^-?space-y-(\d+(\.\d+)?|px|reverse|\[.+\])$/'],

        // Sizing
        'w' => ['/^w-(?!$).+$/'],
        'min-w' => ['/^min-w-(?!$).+$/'],
        'max-w' => ['/^max-w-(?!$).+$/'],
        'h' => ['/^h-(?!$).+$/'],
        'min-h' => ['/^min-h-(?!$).+$/'],
        'max-h' => ['/^max-h-(?!$).+$/'],
        'size' => ['/^size-(?!$).+$/'],

        // Typography
        'font-family' => ['/^font-(sans|serif|mono|\[.+\])$/'],
        'font-size' => ['/^text-(xs|sm|base|lg|xl|[2-9]xl|\[.+\])$/'],
        'font-smoothing' => ['antialiased', 'subpixel-antialiased'],
        'font-style' => ['italic', 'not-italic'],
        'font-weight' => ['/^font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black|\[.+\])$/'],
        'font-variant-numeric' => ['/^(normal-nums|ordinal|slashed-zero|lining-nums|oldstyle-nums|proportional-nums|tabular-nums|diagonal-fractions|stacked-fractions)$/'],
        'letter-spacing' => ['/^tracking-(tighter|tight|normal|wide|wider|widest|\[.+\])$/'],
        'line-clamp' => ['/^line-clamp-(\d+|none)$/'],
        'line-height' => ['/^leading-(\d+(\.\d+)?|none|tight|snug|normal|relaxed|loose|\[.+\])$/'],
        'list-image' => ['/^list-image-(none|\[.+\])$/'],
        'list-style-position' => ['/^list-(inside|outside)$/'],
        'list-style-type' => ['/^list-(none|disc|decimal|\[.+\])$/'],
        'text-align' => ['/^text-(left|center|right|justify|start|end)$/'],
        'text-color' => ['/^text-(?!xs$|sm$|base$|lg$|xl$|[2-9]xl$).+$/'],
        'text-decoration' => ['underline', 'overline', 'line-through', 'no-underline'],
        'text-decoration-color' => ['/^decoration-(?!auto$|from-font$|\d+$|px$).+$/'],
        'text-decoration-style' => ['/^decoration-(solid|double|dotted|dashed|wavy)$/'],
        'text-decoration-thickness' => ['/^decoration-(auto|from-font|\d+|px|\[.+\])$/'],
        'text-underline-offset' => ['/^underline-offset-(auto|\d+|px|\[.+\])$/'],
        'text-transform' => ['uppercase', 'lowercase', 'capitalize', 'normal-case'],
        'text-overflow' => ['truncate', 'text-ellipsis', 'text-clip'],
        'text-wrap' => ['/^text-(wrap|nowrap|balance|pretty)$/'],
        'text-indent' => ['/^indent-(\d+(\.\d+)?|px|\[.+\])$/'],
        'vertical-align' => ['/^align-(baseline|top|middle|bottom|text-top|text-bottom|sub|super|\[.+\])$/'],
        'whitespace' => ['/^whitespace-(normal|nowrap|pre|pre-line|pre-wrap|break-spaces)$/'],
        'word-break' => ['/^break-(normal|words|all|keep)$/'],
        'hyphens' => ['/^hyphens-(none|manual|auto)$/'],

        // Backgrounds
        'bg-attachment' => ['/^bg-(fixed|local|scroll)$/'],
        'bg-clip' => ['/^bg-clip-(border|padding|content|text)$/'],
        'bg-color' => ['/^bg-(?!fixed$|local$|scroll$|clip-|origin-|no-repeat$|repeat|auto$|cover$|contain$|none$|gradient-to-).+$/'],
        'bg-origin' => ['/^bg-origin-(border|padding|content)$/'],
        'bg-position' => ['/^bg-(bottom|center|left|left-bottom|left-top|right|right-bottom|right-top|top|\[.+\])$/'],
        'bg-repeat' => ['/^bg-(no-repeat|repeat|repeat-x|repeat-y|repeat-round|repeat-space)$/'],
        'bg-size' => ['/^bg-(auto|cover|contain|\[.+\])$/'],
        'bg-image' => ['/^bg-(none|gradient-to-(t|tr|r|br|b|bl|l|tl)|\[.+\])$/'],
        'gradient-from' => ['/^from-.+$/'],
        'gradient-via' => ['/^via-.+$/'],
        'gradient-to' => ['/^to-.+$/'],

        // Borders
        'rounded' => ['/^rounded(-(\w+))?(-(\d+(\.\d+)?|px|full|\[.+\]))?$/'],
        'border-w-all' => ['/^border(-(\d+|px|\[.+\]))?$/', '/^border-0$/'],
        'border-w-x' => ['/^border-x(-(\d+|px|\[.+\]))?$/'],
        'border-w-y' => ['/^border-y(-(\d+|px|\[.+\]))?$/'],
        'border-w-t' => ['/^border-t(-(\d+|px|\[.+\]))?$/'],
        'border-w-r' => ['/^border-r(-(\d+|px|\[.+\]))?$/'],
        'border-w-b' => ['/^border-b(-(\d+|px|\[.+\]))?$/'],
        'border-w-l' => ['/^border-l(-(\d+|px|\[.+\]))?$/'],
        'border-color' => ['/^border(-[trbl])?-.+$/'],
        'border-style' => ['/^border-(solid|dashed|dotted|double|hidden|none)$/'],
        'divide-x' => ['/^divide-x(-(\d+|px|reverse|\[.+\]))?$/'],
        'divide-y' => ['/^divide-y(-(\d+|px|reverse|\[.+\]))?$/'],
        'divide-color' => ['/^divide-.+$/'],
        'divide-style' => ['/^divide-(solid|dashed|dotted|double|none)$/'],
        'outline-w' => ['/^outline(-(\d+|px|\[.+\]))?$/'],
        'outline-color' => ['/^outline-.+$/'],
        'outline-style' => ['/^outline-(none|solid|dashed|dotted|double)$/'],
        'outline-offset' => ['/^outline-offset-(\d+|px|\[.+\])$/'],
        'ring-w' => ['/^ring(-(\d+|px|inset|\[.+\]))?$/'],
        'ring-color' => ['/^ring-.+$/'],
        'ring-offset-w' => ['/^ring-offset-(\d+|px|\[.+\])$/'],
        'ring-offset-color' => ['/^ring-offset-.+$/'],

        // Effects
        'shadow' => ['/^shadow(-(\w+|\[.+\]))?$/'],
        'shadow-color' => ['/^shadow-.+$/'],
        'opacity' => ['/^opacity-(\d+|\[.+\])$/'],
        'mix-blend' => ['/^mix-blend-(normal|multiply|screen|overlay|darken|lighten|color-dodge|color-burn|hard-light|soft-light|difference|exclusion|hue|saturation|color|luminosity|plus-lighter)$/'],
        'bg-blend' => ['/^bg-blend-(normal|multiply|screen|overlay|darken|lighten|color-dodge|color-burn|hard-light|soft-light|difference|exclusion|hue|saturation|color|luminosity)$/'],

        // Filters
        'blur' => ['/^blur(-(\w+|\[.+\]))?$/'],
        'brightness' => ['/^brightness-(\d+|\[.+\])$/'],
        'contrast' => ['/^contrast-(\d+|\[.+\])$/'],
        'drop-shadow' => ['/^drop-shadow(-(\w+|\[.+\]))?$/'],
        'grayscale' => ['/^grayscale(-(\d+|\[.+\]))?$/'],
        'hue-rotate' => ['/^hue-rotate-(\d+|\[.+\])$/'],
        'invert' => ['/^invert(-(\d+|\[.+\]))?$/'],
        'saturate' => ['/^saturate-(\d+|\[.+\])$/'],
        'sepia' => ['/^sepia(-(\d+|\[.+\]))?$/'],
        'backdrop-blur' => ['/^backdrop-blur(-(\w+|\[.+\]))?$/'],
        'backdrop-brightness' => ['/^backdrop-brightness-(\d+|\[.+\])$/'],
        'backdrop-contrast' => ['/^backdrop-contrast-(\d+|\[.+\])$/'],
        'backdrop-grayscale' => ['/^backdrop-grayscale(-(\d+|\[.+\]))?$/'],
        'backdrop-hue-rotate' => ['/^backdrop-hue-rotate-(\d+|\[.+\])$/'],
        'backdrop-invert' => ['/^backdrop-invert(-(\d+|\[.+\]))?$/'],
        'backdrop-opacity' => ['/^backdrop-opacity-(\d+|\[.+\])$/'],
        'backdrop-saturate' => ['/^backdrop-saturate-(\d+|\[.+\])$/'],
        'backdrop-sepia' => ['/^backdrop-sepia(-(\d+|\[.+\]))?$/'],

        // Transitions & Animation
        'transition-property' => ['/^transition(-(\w+|\[.+\]))?$/'],
        'transition-duration' => ['/^duration-(\d+|\[.+\])$/'],
        'transition-timing' => ['/^ease-(linear|in|out|in-out|\[.+\])$/'],
        'transition-delay' => ['/^delay-(\d+|\[.+\])$/'],
        'animate' => ['/^animate-(none|spin|ping|pulse|bounce|\[.+\])$/'],

        // Transforms
        'scale' => ['/^scale(-[xy])?-(\d+|\[.+\])$/'],
        'rotate' => ['/^-?rotate-(\d+|\[.+\])$/'],
        'translate-x' => ['/^-?translate-x-(\d+(\.\d+)?\/\d+|\d+(\.\d+)?|px|full|\[.+\])$/'],
        'translate-y' => ['/^-?translate-y-(\d+(\.\d+)?\/\d+|\d+(\.\d+)?|px|full|\[.+\])$/'],
        'skew-x' => ['/^-?skew-x-(\d+|\[.+\])$/'],
        'skew-y' => ['/^-?skew-y-(\d+|\[.+\])$/'],
        'transform-origin' => ['/^origin-(center|top|top-right|right|bottom-right|bottom|bottom-left|left|top-left|\[.+\])$/'],

        // Interactivity
        'accent' => ['/^accent-.+$/'],
        'appearance' => ['/^appearance-(none|auto)$/'],
        'cursor' => ['/^cursor-(auto|default|pointer|wait|text|move|help|not-allowed|none|context-menu|progress|cell|crosshair|vertical-text|alias|copy|no-drop|grab|grabbing|all-scroll|col-resize|row-resize|n-resize|e-resize|s-resize|w-resize|ne-resize|nw-resize|se-resize|sw-resize|ew-resize|ns-resize|nesw-resize|nwse-resize|zoom-in|zoom-out|\[.+\])$/'],
        'caret-color' => ['/^caret-.+$/'],
        'pointer-events' => ['/^pointer-events-(none|auto)$/'],
        'resize' => ['/^resize(-none|-y|-x)?$/'],
        'scroll-behavior' => ['/^scroll-(auto|smooth)$/'],
        'scroll-m' => ['/^scroll-m[trbl]?x?y?-(\d+(\.\d+)?|px|\[.+\])$/'],
        'scroll-p' => ['/^scroll-p[trbl]?x?y?-(\d+(\.\d+)?|px|\[.+\])$/'],
        'scroll-snap-align' => ['/^snap-(start|end|center|align-none)$/'],
        'scroll-snap-stop' => ['/^snap-(normal|always)$/'],
        'scroll-snap-type' => ['/^snap-(none|x|y|both|mandatory|proximity)$/'],
        'touch' => ['/^touch-(auto|none|pan-x|pan-left|pan-right|pan-y|pan-up|pan-down|pinch-zoom|manipulation)$/'],
        'user-select' => ['/^select-(none|text|all|auto)$/'],
        'will-change' => ['/^will-change-(auto|scroll|contents|transform|\[.+\])$/'],
    ];

    private static array $conflictingClassGroups = [
        'overflow' => ['overflow-x', 'overflow-y'],
        'overscroll' => ['overscroll-x', 'overscroll-y'],
        'inset' => ['inset-x', 'inset-y', 'top', 'right', 'bottom', 'left'],
        'inset-x' => ['right', 'left'],
        'inset-y' => ['top', 'bottom'],
        'flex' => ['basis', 'grow', 'shrink'],
        'gap' => ['gap-x', 'gap-y'],
        'p' => ['px', 'py', 'pt', 'pr', 'pb', 'pl'],
        'px' => ['pr', 'pl'],
        'py' => ['pt', 'pb'],
        'm' => ['mx', 'my', 'mt', 'mr', 'mb', 'ml'],
        'mx' => ['mr', 'ml'],
        'my' => ['mt', 'mb'],
        'font-size' => ['line-height'],
        'bg-color' => ['bg-color'],
        'text-color' => ['text-color'],
        'fvn-normal' => ['fvn-ordinal', 'fvn-slashed-zero', 'fvn-figure', 'fvn-spacing', 'fvn-fraction'],
        'rounded' => ['rounded-s', 'rounded-e', 'rounded-t', 'rounded-r', 'rounded-b', 'rounded-l', 'rounded-ss', 'rounded-se', 'rounded-ee', 'rounded-es', 'rounded-tl', 'rounded-tr', 'rounded-br', 'rounded-bl'],
        'rounded-s' => ['rounded-ss', 'rounded-es'],
        'rounded-e' => ['rounded-se', 'rounded-ee'],
        'rounded-t' => ['rounded-tl', 'rounded-tr'],
        'rounded-r' => ['rounded-tr', 'rounded-br'],
        'rounded-b' => ['rounded-br', 'rounded-bl'],
        'rounded-l' => ['rounded-tl', 'rounded-bl'],
        'border-spacing' => ['border-spacing-x', 'border-spacing-y'],
        'border-w-all' => [],
        'border-w-x' => ['border-w-all'],
        'border-w-y' => ['border-w-all'],
        'border-w-t' => ['border-w-all', 'border-w-y'],
        'border-w-r' => ['border-w-all', 'border-w-x'],
        'border-w-b' => ['border-w-all', 'border-w-y'],
        'border-w-l' => ['border-w-all', 'border-w-x'],
        'border-color' => ['border-color-t', 'border-color-r', 'border-color-b', 'border-color-l'],
        'border-color-x' => ['border-color-r', 'border-color-l'],
        'border-color-y' => ['border-color-t', 'border-color-b'],
        'scroll-m' => ['scroll-mx', 'scroll-my', 'scroll-mt', 'scroll-mr', 'scroll-mb', 'scroll-ml'],
        'scroll-mx' => ['scroll-mr', 'scroll-ml'],
        'scroll-my' => ['scroll-mt', 'scroll-mb'],
        'scroll-p' => ['scroll-px', 'scroll-py', 'scroll-pt', 'scroll-pr', 'scroll-pb', 'scroll-pl'],
        'scroll-px' => ['scroll-pr', 'scroll-pl'],
        'scroll-py' => ['scroll-pt', 'scroll-pb'],
    ];

    public static function merge(string|array ...$inputs): string
    {
        $allClasses = [];

        foreach ($inputs as $input) {
            if (is_array($input)) {
                $allClasses = array_merge($allClasses, $input);
            } else {
                $classes = preg_split('/\s+/', trim($input));
                $allClasses = array_merge($allClasses, array_filter($classes));
            }
        }

        return self::mergeClassList($allClasses);
    }

    private static function mergeClassList(array $classes): string
    {
        $result = [];

        foreach ($classes as $originalClass) {
            if (empty(trim($originalClass))) {
                continue;
            }

            $classKey = self::getClassGroup($originalClass);

            $conflictingKeys = self::getConflictingKeys($classKey);
            foreach ($conflictingKeys as $key) {
                unset($result[$key]);
            }

            $result[$classKey] = $originalClass;
        }

        return implode(' ', array_values($result));
    }

    private static function getClassGroup(string $class): string
    {
        $pattern = '/^((?:[^:]+:)*)([^:]+)$/';
        if (preg_match($pattern, $class, $matches)) {
            $prefixes = $matches[1];
            $utilityClass = $matches[2];

            foreach (self::$classGroups as $groupKey => $patterns) {
                foreach ($patterns as $pattern) {
                    if (is_string($pattern) && str_starts_with($pattern, '/')) {
                        if (preg_match($pattern, $utilityClass)) {
                            return $prefixes . $groupKey;
                        }
                    } else {
                        if ($pattern === $utilityClass) {
                            return $prefixes . $groupKey;
                        }
                    }
                }
            }
            return $prefixes . $utilityClass;
        }
        return $class;
    }

    private static function getConflictingKeys(string $classKey): array
    {
        $baseClassKey = preg_replace("/^(?:[^:]+:)+/", "", $classKey);
        if (isset(self::$conflictingClassGroups[$baseClassKey])) {
            $prefix = preg_replace("/" . preg_quote($baseClassKey, "/") . '$/i', "", $classKey);
            return array_map(function ($conflict) use ($prefix) {
                return $prefix . $conflict;
            }, self::$conflictingClassGroups[$baseClassKey]);
        }
        return [$classKey];
    }
}
