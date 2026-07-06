<?php

namespace Mmoollllee\Cms\Support;

use Closure;
use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * WordPress-style shortcode processor for content pages — the shared mechanism.
 *
 * Replaces [shortcode] and [shortcode attr="value"] tokens with rendered HTML.
 * Ships generic tenant-contact shortcodes ([logo], [company_name], [contact_email],
 * [contact_phone], [street], [postal_code], [city], [contact_address]); projects add
 * their own via the extension hook + configure the RichEditor merge-tag labels.
 *
 * Usage in Blade (rich text):
 *   {!! \Mmoollllee\Cms\Support\Shortcodes::render($html) !!}
 *
 * Project configuration (in a service provider's boot()):
 *   Shortcodes::extendDefaultsUsing(function (): void {
 *       Shortcodes::register('my_tag', fn (array $attrs) => '<span>…</span>');
 *       Shortcodes::registerMergeTagValue('my_tag', fn () => '…');
 *   });
 *   // RichEditor labels: Shortcodes::useMergeTags([key => label, …])
 */
class Shortcodes
{
    /**
     * Generic merge tags shared across projects (tenant contact fields) — the
     * canonical default label list for the RichEditor UI. Replace the list per
     * project via {@see useMergeTags()}.
     *
     * @var array<string, string> shortcode name → label for the RichEditor UI
     */
    public const DEFAULT_MERGE_TAGS = [
        'company_name' => 'Firmenname',
        'contact_email' => 'E-Mail (Adresse)',
        'contact_email_link' => 'E-Mail (Link)',
        'contact_phone' => 'Telefon (Nummer)',
        'contact_phone_link' => 'Telefon (Link)',
        'street' => 'Straße',
        'postal_code' => 'PLZ',
        'city' => 'Stadt',
        'contact_address' => 'Adresse (mehrzeilig)',
    ];

    /** Tenant setting keys exposed as plain-value shortcodes + merge tags. */
    protected const SETTING_KEYS = ['company_name', 'contact_email', 'contact_phone', 'street', 'postal_code', 'city'];

    /** @var array<string, callable(array<string, string>): string> */
    protected static array $handlers = [];

    /** @var array<string, Closure(): (string|HtmlString)> Project-registered merge-tag values. */
    protected static array $mergeTagValues = [];

    /** @var array<int, Closure(): void> Project extension callbacks, re-run on each boot. */
    protected static array $extensions = [];

    /** @var array<string, string>|null Project-replaced merge-tag label list. */
    protected static ?array $mergeTags = null;

    protected static bool $booted = false;

    /**
     * Replace all [shortcode] tokens in the given text.
     */
    public static function render(?string $text): string
    {
        if (blank($text) || ! str_contains($text, '[')) {
            return $text ?? '';
        }

        static::boot();

        return preg_replace_callback(
            '/\[([a-z_-]+)(\s[^\]]*?)?\]/i',
            function (array $matches): string {
                $name = strtolower($matches[1]);
                $rawAttrs = trim($matches[2] ?? '');

                if (! isset(static::$handlers[$name])) {
                    return $matches[0];
                }

                return call_user_func(static::$handlers[$name], static::parseAttributes($rawAttrs));
            },
            $text
        );
    }

    /**
     * Register a shortcode handler.
     *
     * @param  callable(array<string, string>): string  $handler
     */
    public static function register(string $name, callable $handler): void
    {
        static::$handlers[strtolower($name)] = $handler;
    }

    /**
     * Register a merge-tag value (consumed by the RichContentRenderer).
     *
     * @param  Closure(): (string|HtmlString)  $value
     */
    public static function registerMergeTagValue(string $key, Closure $value): void
    {
        static::$mergeTagValues[$key] = $value;
    }

    /**
     * Register a project extension callback. Runs on every boot (survives reset()),
     * so projects can add their own shortcodes + merge-tag values.
     *
     * @param  Closure(): void  $callback
     */
    public static function extendDefaultsUsing(Closure $callback): void
    {
        static::$extensions[] = $callback;
    }

    /**
     * Reset registered handlers/values (useful for testing). Keeps the project
     * extension callbacks so the next boot re-registers them.
     */
    public static function reset(): void
    {
        static::$handlers = [];
        static::$mergeTagValues = [];
        static::$booted = false;
    }

    protected static function boot(): void
    {
        if (static::$booted) {
            return;
        }

        static::$booted = true;
        static::registerDefaults();

        foreach (static::$extensions as $extension) {
            $extension();
        }
    }

    /**
     * Replace the merge-tag label list shown in the RichEditor picker
     * (call from a service provider; the complete key → label map).
     *
     * @param  array<string, string>  $tags
     */
    public static function useMergeTags(array $tags): void
    {
        static::$mergeTags = $tags;
    }

    /**
     * Merge tag definitions for the RichEditor UI (key → label).
     *
     * @return array<string, string>
     */
    public static function mergeTags(): array
    {
        return static::$mergeTags ?? static::DEFAULT_MERGE_TAGS;
    }

    /**
     * Merge tag values for the RichContentRenderer.
     *
     * Uses lazy closures so tenant lookups only run when a tag is actually
     * encountered. Generic tenant fields plus any project-registered values.
     *
     * @return array<string, Closure(): (string|HtmlString)>
     */
    public static function mergeTagValues(): array
    {
        static::boot();

        $values = [];

        foreach (static::SETTING_KEYS as $key) {
            $values[$key] = fn (): string => app(CurrentTenant::class)->get()?->resolvedSiteSetting($key) ?? '';
        }

        $values['contact_address'] = static::contactAddressHtml(...);

        return [...$values, ...static::$mergeTagValues];
    }

    /**
     * Build multi-line contact address HTML from tenant settings.
     */
    protected static function contactAddressHtml(): HtmlString
    {
        $tenant = app(CurrentTenant::class)->get();

        if (! $tenant) {
            return new HtmlString('');
        }

        $companyName = $tenant->resolvedSiteSetting('company_name');
        $street = $tenant->resolvedSiteSetting('street');
        $postalCode = $tenant->resolvedSiteSetting('postal_code');
        $city = $tenant->resolvedSiteSetting('city');

        if (blank($street) || blank($postalCode) || blank($city)) {
            return new HtmlString('');
        }

        $lines = [];
        if (filled($companyName)) {
            $lines[] = e($companyName);
        }
        $lines[] = e($street);
        $lines[] = e($postalCode).' '.e($city);

        return new HtmlString(implode('<br>', $lines));
    }

    protected static function registerDefaults(): void
    {
        static::register('logo', function (array $attrs): string {
            return svg('image-logo', $attrs['class'] ?? null)->toHtml();
        });

        foreach (static::SETTING_KEYS as $key) {
            static::register($key, function () use ($key): string {
                return e(app(CurrentTenant::class)->get()?->resolvedSiteSetting($key) ?? '');
            });
        }

        static::register('contact_address', fn (): string => (string) static::contactAddressHtml());
    }

    /**
     * Parse shortcode attributes from a raw string.
     *
     * Supports: attr="value" and attr='value'
     * Also handles HTML-escaped quotes (&quot;) from e() in Blade templates.
     *
     * @return array<string, string>
     */
    protected static function parseAttributes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $raw = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');

        $attrs = [];
        preg_match_all('/([a-z_-]+)\s*=\s*["\']([^"\']*?)["\']/i', $raw, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attrs[strtolower($match[1])] = $match[2];
        }

        return $attrs;
    }
}
