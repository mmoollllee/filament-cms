<?php

namespace Mmoollllee\Cms\Support;

use DOMDocument;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Support\Analytics\Umami;
use Mmoollllee\Filami\Filami;

/**
 * Rewrites editorial `mailto:`/`tel:` anchors into the spamprotect components
 * that hide the address from scrapers.
 *
 * SECURITY: everything taken from the document — the link label above all —
 * must be passed to {@see Blade::render()} as DATA, never concatenated into the
 * template string. Blade COMPILES its first argument, so a concatenated label
 * turns editorial text into server-side code: `e()` escapes `<>"'&` but leaves
 * `{{ }}`, `{!! !!}` and `@php` untouched, so a label like `{{ phpversion() }}`
 * would execute. The label reaches us from any tenant editor via the link
 * picker or the block's HTML source tab, so that is a straight path from
 * editorial content to RCE. Keep the template a literal — which is why the
 * analytics attribute below is bound (`:data-filami-event="$event"`) rather
 * than interpolated, even though its value comes from config.
 */
class SpamprotectHtml
{
    public static function protectEmails(string $html): string
    {
        if ($html === '' || (! Str::contains($html, 'mailto:') && ! Str::contains($html, 'tel:'))) {
            return $html;
        }

        // Constant for the whole document; resolving them per anchor meant an
        // Umami::installed() probe plus two config reads for every link.
        $phoneEvent = self::umamiEvent(Filami::phoneEvent(...));
        $emailEvent = self::umamiEvent(Filami::emailEvent(...));

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
                $blade = '<x-encrypt-phone :phone="$phone" :data-filami-event="$event">{{ $label }}</x-encrypt-phone>';
                $protected = Blade::render($blade, [
                    'phone' => $phone,
                    'label' => $label,
                    'event' => $phoneEvent,
                ]);
            } elseif (self::isMailto($href)) {
                $email = substr($href, 7); // after "mailto:"
                $label = $a->textContent == $email ? '' : $a->textContent;

                // Rebuild this <a> via the Blade component from the package:
                $blade = '<x-encrypt-email :email="$email" :data-filami-event="$event">{{ $label }}</x-encrypt-email>';
                $protected = Blade::render($blade, [
                    'email' => $email,
                    'label' => $label,
                    'event' => $emailEvent,
                ]);
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

    /**
     * Event name for a protected link, or null to leave it unmeasured.
     *
     * These anchors render as `href="#"` with the address encrypted, so
     * filami's delegated listener cannot recognise them by href and reads this
     * attribute instead.
     *
     * NOT Umami's own `data-umami-event`: its tracker attaches a capture-phase
     * handler to those anchors which calls preventDefault() and then forces
     * location.href back to the element's own href. On an `href="#"` link that
     * races the spamprotect handler decrypting the address, and can clobber the
     * mailto:/tel: navigation the visitor actually clicked.
     *
     * Deliberately the NAME only: attaching the address as event data would
     * put it back into the markup in clear text and undo the whole point of
     * encrypting the link.
     */
    protected static function umamiEvent(callable $resolve): ?string
    {
        // filami owns the names and the switch — the CMS only decides that
        // these particular anchors carry one. A second copy of the resolution
        // here would let the two halves of the same funnel drift apart.
        return Umami::installed() && Filami::linkEventsEnabled() ? $resolve() : null;
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
