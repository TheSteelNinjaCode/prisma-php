<?php

declare(strict_types=1);

namespace Lib;

use DOMDocument;
use DOMXPath;
use DOMElement;

final class PartialRenderer
{
    /** @return array<string,string> selector => outerHTML */
    public static function extract(string $html, array $selectors): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();

        $xpath   = new DOMXPath($doc);
        $payload = [];

        foreach ($selectors as $rawSel) {
            $sel = preg_replace('/[^-_\w]/', '', (string)$rawSel);

            /** @var DOMElement|null $node */
            $node = $xpath->query("//*[@pp-sync='{$sel}']")->item(0);

            if (!$node && $sel !== '') {
                $node = $xpath->query("//*[@id='{$sel}' or contains(concat(' ',normalize-space(@class),' '),' {$sel} ')]")->item(0);
            }

            if ($node) {
                $payload[$sel] = $doc->saveHTML($node);
            }
        }
        return $payload;
    }
}
