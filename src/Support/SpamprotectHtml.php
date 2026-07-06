<?php

namespace Mmoollllee\Cms\Support;

use DOMDocument;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

class SpamprotectHtml
{
    public static function protectEmails(string $html): string
    {
        if ($html === '' || (! Str::contains($html, 'mailto:') && ! Str::contains($html, 'tel:'))) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        // Load with proper encoding and avoid auto-adding <html><body>
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            $protected = null;
            if (self::isTel($href)) {
                $phone = substr($href, 4); // after "tel:"
                $label = $a->textContent == $phone ? '' : $a->textContent;

                // Rebuild this <a> via the Blade component from the package:
                $blade = '<x-encrypt-phone :phone="$phone">'.e($label).'</x-encrypt-phone>';
                $protected = Blade::render($blade, ['phone' => $phone]);
            } elseif (self::isMailto($href)) {
                $email = substr($href, 7); // after "mailto:"
                $label = $a->textContent == $email ? '' : $a->textContent;

                // Rebuild this <a> via the Blade component from the package:
                $blade = '<x-encrypt-email :email="$email">'.e($label).'</x-encrypt-email>';
                $protected = Blade::render($blade, ['email' => $email]);
            } else {
                continue;
            }

            // Replace the node with the rendered HTML
            $frag = $dom->createDocumentFragment();
            $frag->appendXML($protected);
            $a->parentNode->replaceChild($frag, $a);
        }

        $out = $dom->saveHTML();
        libxml_clear_errors();

        // remove the xml encoding header we prefixed
        return preg_replace('/^<\?xml.*?\?>/', '', $out, 1);
    }

    protected static function isMailto(string $href): bool
    {
        return str_starts_with($href, 'mailto:');
    }

    protected static function isTel(string $href): bool
    {
        return str_starts_with($href, 'tel:');
    }
}
