<?php

namespace Mmoollllee\Cms\Support\Seo;

use Closure;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

/**
 * Extension seams for the shared SEO head (<x-site.seo-head> component and the
 * layout <title>).
 *
 * The component renders the brand-agnostic SEO head for every project: title
 * and description (honouring the SeoFields meta.* overrides), canonical URL,
 * Open Graph / Twitter Card, a robots directive and JSON-LD (Organization +
 * BreadcrumbList). Projects adapt the variable spots without copying the view:
 *
 * TYPE-OWNED rules belong on the content type itself — override
 * ContentBlueprint::noindex() in the blueprint (preferred):
 *
 *   // app/Sites/{Site}/{Type}/Blueprint.php
 *   public function noindex(Content $content): bool
 *   {
 *       return data_get($content->payload, 'is_vergeben') === true;
 *   }
 *
 * CROSS-CUTTING rules and extra JSON-LD register in a service provider's boot():
 *
 *   SeoHead::noindexWhen(fn (?Content $content, Tenant $tenant): bool
 *       => $tenant->isStaging());
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

    /**
     * Editorial meta.noindex toggle (SeoFields), the content type's own
     * blueprint signal ({@see ContentBlueprint::noindex()}) OR any registered
     * cross-cutting rule — first hit wins.
     */
    public static function isNoindex(?Content $content, Tenant $tenant): bool
    {
        if ((bool) data_get($content, 'meta.noindex')) {
            return true;
        }

        if ($content !== null && static::blueprintFor($content, $tenant)?->noindex($content)) {
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
     * The page's Open Graph image as an ABSOLUTE URL (crawlers reject relative
     * ones): per-content media-library ref (`meta.og_image`, pre-cropped `og`
     * conversion) → legacy per-content URL (`meta.og_image_url`) → the
     * tenant's default OG image. Single source for the component, and the
     * override seam for apps with their own cascade.
     */
    public static function ogImageUrl(?Content $content, Tenant $tenant): ?string
    {
        $url = MediaUrlResolver::absoluteUrl(data_get($content, 'meta.og_image'), 'og')
            ?? (data_get($content, 'meta.og_image_url') ?: $tenant->resolvedDefaultOgImageUrl());

        if (blank($url)) {
            return null;
        }

        return Str::startsWith($url, ['http://', 'https://', '//']) ? $url : url($url);
    }

    /**
     * The content's blueprint, resolved against the rendering tenant's site
     * (the frontend invariant: seo-head renders content of the current tenant).
     */
    protected static function blueprintFor(Content $content, Tenant $tenant): ?ContentBlueprint
    {
        $contentType = (string) data_get($content, 'content_type');

        if ($contentType === '') {
            return null;
        }

        return app(ContentBlueprintRegistry::class)->find(
            $contentType,
            data_get($tenant, 'site_key'),
        );
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
