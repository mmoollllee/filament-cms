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
 *   // Packages ADD a tag to that list: Shortcodes::registerMergeTag(key, label, value)
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

    /** @var array<string, string> Package-contributed merge-tag labels, merged on top of the list. */
    protected static array $extraMergeTags = [];

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
     * Register an ADDITIONAL merge tag (picker label + value) — for packages
     * that contribute a tag to whatever list the app runs with, e.g.
     * filament-consent-control's cookie-settings button.
     *
     * Unlike {@see useMergeTags()}, which replaces the default label list, these
     * survive that replacement: an app curating its own labels must not have to
     * know about every installed package. Call it from inside an
     * {@see extendDefaultsUsing()} callback so it survives {@see reset()} too.
     *
     * @param  Closure(): (string|HtmlString)|null  $value  omit when the tag is only a label
     */
    public static function registerMergeTag(string $key, string $label, ?Closure $value = null): void
    {
        static::$extraMergeTags[$key] = $label;

        if ($value !== null) {
            static::registerMergeTagValue($key, $value);
        }
    }

    /**
     * Register a project extension callback. Runs on every boot (survives reset()),
     * so projects and packages can add their own shortcodes + merge-tag values.
     *
     * @param  Closure(): void  $callback
     */
    public static function extendDefaultsUsing(Closure $callback): void
    {
        static::$extensions[] = $callback;

        // Registered after something already triggered the boot (a package
        // provider booting late, a shortcode rendered during boot): apply it
        // now — the boot only runs the list once, so it would otherwise be
        // silently missing until the next reset().
        if (static::$booted) {
            $callback();
        }
    }

    /**
     * Reset registered handlers/values AND a replaced label list (useful for
     * testing — a test that calls useMergeTags() would otherwise leak its list
     * into every later test in the process). Keeps the project extension
     * callbacks so the next boot re-registers them; an app's useMergeTags()
     * call sits in a provider boot and is re-applied with the next app boot.
     */
    public static function reset(): void
    {
        static::$handlers = [];
        static::$mergeTagValues = [];
        static::$extraMergeTags = [];
        static::$mergeTags = null;
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
     * Boots first: package tags registered via {@see registerMergeTag()} inside
     * an {@see extendDefaultsUsing()} callback would otherwise be missing from
     * the picker, since nothing else on the editor path triggers the boot.
     *
     * @return array<string, string>
     */
    public static function mergeTags(): array
    {
        static::boot();

        return [
            ...static::$mergeTags ?? static::DEFAULT_MERGE_TAGS,
            ...static::$extraMergeTags,
        ];
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
     * SECURITY: values are returned HTML-ESCAPED, because a shortcode's job is
     * to emit HTML and handlers drop attributes straight into a tag (the [logo]
     * handler hands `class` to blade-icons, which does not escape). The
     * html_entity_decode() above is what makes this necessary: it is needed to
     * parse `class=&quot;…&quot;` as written by the editor, but it also undoes
     * TipTap's escaping and any e() a calling view already applied — so without
     * re-escaping here, `<` and `>` survive into the attribute value and break
     * out of the tag. Handlers that need the raw text must decode explicitly.
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
            $attrs[strtolower($match[1])] = e($match[2]);
        }

        return $attrs;
    }
}
