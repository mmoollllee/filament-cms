<?php

namespace Mmoollllee\Cms\Support\Seo;

use Closure;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;

/**
 * Extension seams for the shared SEO head (<x-site.seo-head> component and the
 * layout <title>).
 *
 * The component renders the brand-agnostic SEO head for every project: title
 * and description (honouring the SeoFields meta.* overrides), canonical URL,
 * Open Graph / Twitter Card, a robots directive and JSON-LD (Organization +
 * BreadcrumbList). Projects adapt the two variable spots without copying the
 * view — in a service provider's boot():
 *
 *   SeoHead::noindexWhen(fn (?Content $content, Tenant $tenant): bool
 *       => data_get($content, 'content_type') === 'jobs.job'
 *          && data_get($content?->payload, 'is_vergeben') === true);
 *
 *   SeoHead::addSchema(fn (?Content $content, Tenant $tenant): ?array => [
 *       '@context' => 'https://schema.org',
 *       '@type' => 'LocalBusiness',
 *       'name' => $tenant->displayName(),
 *   ]);
 *
 * A full view override (app resources/views/components/site/seo-head.blade.php)
 * remains possible as a last resort, but forfeits central updates — prefer the
 * seams. See docs/CUSTOMIZATION.md ("SEO head").
 */
class SeoHead
{
    /** @var list<Closure(Content|null, Tenant): bool> */
    protected static array $noindexRules = [];

    /** @var list<Closure(Content|null, Tenant): (array<string, mixed>|null)> */
    protected static array $schemaProviders = [];

    /**
     * Register a project rule that forces `noindex, follow` for matching pages
     * (in addition to the editorial meta.noindex toggle).
     *
     * @param  Closure(Content|null, Tenant): bool  $rule
     */
    public static function noindexWhen(Closure $rule): void
    {
        static::$noindexRules[] = $rule;
    }

    /**
     * Register an additional JSON-LD schema for the head. The provider runs per
     * page and may return null to skip; the array is json_encoded with the
     * hardened flag set (JSON_HEX_TAG) by the component.
     *
     * @param  Closure(Content|null, Tenant): (array<string, mixed>|null)  $provider
     */
    public static function addSchema(Closure $provider): void
    {
        static::$schemaProviders[] = $provider;
    }

    /**
     * Page title: the editorial SEO override (meta.seo_title) wins over the
     * tenant's default title composition. Single source of truth for the
     * layout <title> and the og:/twitter: titles.
     */
    public static function title(?Content $content, Tenant $tenant): string
    {
        return (string) (data_get($content, 'meta.seo_title') ?: $tenant->frontendTitleFor($content));
    }

    /** Editorial meta.noindex toggle (SeoFields) OR any registered project rule. */
    public static function isNoindex(?Content $content, Tenant $tenant): bool
    {
        if ((bool) data_get($content, 'meta.noindex')) {
            return true;
        }

        foreach (static::$noindexRules as $rule) {
            if ($rule($content, $tenant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Additional registered schemas for the page. The base Organization and
     * BreadcrumbList schemas always come from the component itself.
     *
     * @return list<array<string, mixed>>
     */
    public static function schemas(?Content $content, Tenant $tenant): array
    {
        return array_values(array_filter(array_map(
            fn (Closure $provider): ?array => $provider($content, $tenant),
            static::$schemaProviders,
        )));
    }

    /** Drop all registered project seams (tests). */
    public static function reset(): void
    {
        static::$noindexRules = [];
        static::$schemaProviders = [];
    }
}
