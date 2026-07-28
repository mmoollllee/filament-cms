<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Mmoollllee\Cms\Fields\LinkFields;

/**
 * Reads a {@see LinkFields} group from a payload array
 * and renders it as anchor attributes:
 *
 *     @php $link = PayloadLink::from($content->payload, 'link'); @endphp
 *
 *     @if ($link->hasUrl())
 *         <a {{ $link->attributes(['class' => 'btn btn-sm']) }}>{{ $link->labelOr('Mehr erfahren') }}</a>
 *
 *     @endif
 *
 * `attributes()` carries href, title, rel, wire:navigate and the editor's
 * custom CSS classes (merged into any passed class list); pass
 * `withClass: false` where the custom classes must not apply (e.g. a linked
 * thumbnail that has its own layout classes).
 *
 * SECURITY: the URL is scheme-checked once in {@see from()}, so `$url` is safe
 * for every consumer and a rejected link simply reports hasUrl() === false —
 * the view skips it instead of rendering a dead anchor. Blade escapes HTML
 * entities in an href but does NOT check the scheme, so a stored
 * `javascript:`/`data:` URL would otherwise fire on click for any visitor.
 * The link fields are free-text inputs, so the stored value is never trusted.
 */
final class PayloadLink
{
    /**
     * Schemes an editorial link may carry. Schemeless values (relative paths,
     * anchors) pass through untouched — they are the common case in this CMS.
     */
    public const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private function __construct(
        public readonly ?string $url,
        public readonly ?string $label,
        public readonly ?string $title,
        public readonly ?string $cssClass,
        public readonly ?string $rel,
        public readonly bool $wireNavigate,
    ) {}

    public static function from(mixed $payload, string $base = 'link'): self
    {
        return new self(
            url: self::safeUrl(self::stringOrNull(data_get($payload, $base))),
            label: self::stringOrNull(data_get($payload, "{$base}_label")),
            title: self::stringOrNull(data_get($payload, "{$base}_title")),
            cssClass: self::stringOrNull(data_get($payload, "{$base}_class")),
            rel: self::stringOrNull(data_get($payload, "{$base}_rel")),
            wireNavigate: (bool) data_get($payload, "{$base}_wire_navigate"),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    /**
     * Drop any URL whose scheme is not in {@see ALLOWED_SCHEMES}. Str::sanitizeUrl()
     * also sees through entity-encoded and whitespace-obfuscated schemes
     * (`&#106;avascript:`, `java\tscript:`) and returns null for them.
     */
    public static function safeUrl(?string $url): ?string
    {
        return Str::sanitizeUrl($url, self::ALLOWED_SCHEMES);
    }

    public function hasUrl(): bool
    {
        return filled($this->url);
    }

    public function labelOr(string $fallback): string
    {
        return filled($this->label) ? $this->label : $fallback;
    }

    /**
     * Anchor attributes as a ComponentAttributeBag (renders escaped in Blade).
     *
     * @param  array<string, mixed>  $extra  Merged on top; class lists combine.
     */
    public function attributes(array $extra = [], bool $withClass = true): ComponentAttributeBag
    {
        $bag = new ComponentAttributeBag(array_filter([
            'href' => $this->url,
            'title' => $this->title,
            'rel' => $this->rel,
            'wire:navigate' => $this->wireNavigate ?: null,
            'class' => $withClass ? $this->cssClass : null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        return $extra === [] ? $bag : $bag->merge($extra);
    }
}
