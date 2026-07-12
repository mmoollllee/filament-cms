<?php

namespace Mmoollllee\Cms\Concerns\Tenant;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Enums\SocialNetwork;

/**
 * The tenant branding cascade: every resolved*() value falls back from the
 * tenant's own attribute to the branding tenant's, then to a default.
 *
 * Host-model expectations: string columns `brand_name`, `brand_claim`,
 * `logo_path`, `secondary_logo_path`, `favicon_path`, `primary_color`,
 * `default_seo_title`, `default_seo_description`, `default_og_image_path`
 * and an array-cast `social_links`.
 */
trait InheritsBranding
{
    public const DEFAULT_PRIMARY_COLOR = '#005f4e';

    /**
     * Returns the tenant whose branding (logo, colors, site settings) is inherited
     * by other tenants that have no own values configured.
     *
     * Resolution order:
     * 1. Tenant with the ID specified in config('cms.default_branding_tenant_id') if set
     * 2. Otherwise, the tenant with the lowest ID (i.e. the first one ever created)
     *
     * Override via CMS_BRANDING_TENANT_ID env var.
     *
     * @see self::inheritedBrandingTenant() — used by all resolved*() methods
     * @see self::resolvedSiteSetting() — cascades: own → branding tenant → default
     */
    public static function defaultBrandingTenant(): ?self
    {
        // once(): every resolved*() call on a page routes through here — without
        // memoization each unresolved setting fires its own tenant query.
        // Laravel flushes the once-cache per request (and per test).
        return once(function (): ?self {
            $configuredId = config('cms.default_branding_tenant_id');

            return filled($configuredId)
                ? static::find($configuredId)
                : static::query()->orderBy('id')->first();
        });
    }

    public function displayName(): string
    {
        return $this->resolvedBrandName();
    }

    public function isBrandingSource(): bool
    {
        $brandingTenant = static::defaultBrandingTenant();

        return $brandingTenant?->is($this) ?? false;
    }

    public function resolvedBrandName(): string
    {
        $brandName = trim((string) $this->brand_name);

        if (filled($brandName)) {
            return $brandName;
        }

        $brandingTenant = $this->inheritedBrandingTenant();

        if ($brandingTenant instanceof self) {
            $inheritedBrandName = trim((string) $brandingTenant->brand_name);

            return filled($inheritedBrandName) ? $inheritedBrandName : $brandingTenant->name;
        }

        return $this->name;
    }

    public function resolvedBrandClaim(): ?string
    {
        $brandClaim = trim((string) $this->brand_claim);

        if (filled($brandClaim)) {
            return $brandClaim;
        }

        $brandingTenant = $this->inheritedBrandingTenant();

        if (! $brandingTenant instanceof self) {
            return null;
        }

        $inheritedBrandClaim = trim((string) $brandingTenant->brand_claim);

        return filled($inheritedBrandClaim) ? $inheritedBrandClaim : null;
    }

    public function resolvedMainLogoPath(): ?string
    {
        return $this->resolveInheritedAssetPath('logo_path');
    }

    public function resolvedMainLogoUrl(): ?string
    {
        return $this->publicAssetUrl($this->resolvedMainLogoPath());
    }

    public function resolvedLogoPath(): ?string
    {
        return $this->resolvedMainLogoPath();
    }

    public function resolvedLogoUrl(): ?string
    {
        return $this->resolvedMainLogoUrl();
    }

    public function resolvedSecondaryLogoPath(): ?string
    {
        return $this->resolveInheritedAssetPath('secondary_logo_path');
    }

    public function resolvedSecondaryLogoUrl(): ?string
    {
        return $this->publicAssetUrl($this->resolvedSecondaryLogoPath());
    }

    /** Dedicated raster e-mail logo path, with branding inheritance (null when unset). */
    public function resolvedMailLogoPath(): ?string
    {
        return $this->resolveInheritedAssetPath('mail_logo_path');
    }

    /**
     * A mail-client-safe (raster, absolute) logo URL: the dedicated mail logo if set,
     * else the main logo when it is a raster image, else null (the mail layout then
     * falls back to the brand name as text — SVG isn't linked, as many clients drop it).
     *
     * @see \Mmoollllee\Cms\Support\Mail\MailLogo
     */
    public function resolvedMailLogoUrl(): ?string
    {
        return \Mmoollllee\Cms\Support\Mail\MailLogo::urlFor($this);
    }

    public function resolvedFaviconPath(): ?string
    {
        return $this->resolveInheritedAssetPath('favicon_path');
    }

    public function resolvedFaviconUrl(): ?string
    {
        return $this->publicAssetUrl($this->resolvedFaviconPath());
    }

    public function resolvedPrimaryColor(): string
    {
        $primaryColor = trim((string) $this->primary_color);

        if (filled($primaryColor)) {
            return $primaryColor;
        }

        $brandingTenant = $this->inheritedBrandingTenant();

        if ($brandingTenant instanceof self) {
            $brandingPrimaryColor = trim((string) $brandingTenant->primary_color);

            if (filled($brandingPrimaryColor)) {
                return $brandingPrimaryColor;
            }
        }

        return static::DEFAULT_PRIMARY_COLOR;
    }

    public function resolvedSiteSetting(string $field, mixed $default = null): mixed
    {
        $configuredValue = $this->getAttribute($field);

        if (static::hasResolvedValue($configuredValue)) {
            return $configuredValue;
        }

        $brandingTenant = $this->inheritedBrandingTenant();

        if ($brandingTenant instanceof self) {
            $inheritedValue = $brandingTenant->getAttribute($field);

            if (static::hasResolvedValue($inheritedValue)) {
                return $inheritedValue;
            }
        }

        return $default;
    }

    public function resolvedSocialLinksForDisplay(): array
    {
        return static::socialLinksForDisplayValue($this->resolvedSiteSetting('social_links', []));
    }

    /**
     * @return list<array{network: string, url: string}>
     */
    public static function normalizeSocialLinks(mixed $socialLinks): array
    {
        if (! is_array($socialLinks)) {
            return [];
        }

        if (array_is_list($socialLinks)) {
            return collect($socialLinks)
                ->map(function (mixed $link): ?array {
                    $network = SocialNetwork::tryFrom((string) data_get($link, 'network'));
                    $url = trim((string) data_get($link, 'url'));

                    if (! $network || blank($url)) {
                        return null;
                    }

                    return [
                        'network' => $network->value,
                        'url' => $url,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        return collect($socialLinks)
            ->map(function (mixed $url, mixed $network): ?array {
                $resolvedNetwork = SocialNetwork::tryFrom((string) $network);
                $resolvedUrl = trim((string) $url);

                if (! $resolvedNetwork || blank($resolvedUrl)) {
                    return null;
                }

                return [
                    'network' => $resolvedNetwork->value,
                    'url' => $resolvedUrl,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{network: string, label: string, icon: string, url: string}>
     */
    public static function socialLinksForDisplayValue(mixed $socialLinks): array
    {
        return collect(static::normalizeSocialLinks($socialLinks))
            ->map(function (array $link): array {
                $network = SocialNetwork::from($link['network']);

                return [
                    'network' => $network->value,
                    'label' => $network->label(),
                    'icon' => $network->icon(),
                    'url' => $link['url'],
                ];
            })
            ->all();
    }

    public function resolvedDefaultSeoDescription(): ?string
    {
        $description = trim((string) $this->resolvedSiteSetting('default_seo_description'));

        return filled($description) ? $description : null;
    }

    public function resolvedDefaultOgImagePath(): ?string
    {
        $path = trim((string) $this->resolvedSiteSetting('default_og_image_path'));

        return filled($path) ? $path : null;
    }

    public function resolvedDefaultOgImageUrl(): ?string
    {
        $path = $this->resolvedDefaultOgImagePath();

        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    public function computedDefaultSeoTitle(): string
    {
        $displayName = $this->displayName();
        $brandClaim = $this->resolvedBrandClaim();

        if (blank($brandClaim)) {
            return $displayName;
        }

        return "{$displayName} – {$brandClaim}";
    }

    public function resolvedDefaultSeoTitle(): string
    {
        $configuredDefaultSeoTitle = trim((string) $this->resolvedSiteSetting('default_seo_title'));

        if (filled($configuredDefaultSeoTitle)) {
            return $configuredDefaultSeoTitle;
        }

        return $this->computedDefaultSeoTitle();
    }

    public function frontendTitleFor(?Content $content = null): string
    {
        if (! $content instanceof Content) {
            return $this->resolvedDefaultSeoTitle();
        }

        return $this->frontendTitleForValues($content->title, $content->path);
    }

    /**
     * The frontend document title for a raw title/path pair — the single source
     * of the composition rule, shared by frontendTitleFor() and panel code that
     * works on unsaved form state (the SeoFields placeholder).
     */
    public function frontendTitleForValues(?string $title, ?string $path): string
    {
        $title = trim((string) $title);

        if ($title === '' || $path === '/' || in_array(Str::lower($title), ['start', 'home'], true)) {
            return $this->resolvedDefaultSeoTitle();
        }

        return "{$title} – {$this->displayName()}";
    }

    protected function inheritedBrandingTenant(): ?self
    {
        $brandingTenant = static::defaultBrandingTenant();

        if (! $brandingTenant instanceof self || $brandingTenant->is($this)) {
            return null;
        }

        return $brandingTenant;
    }

    protected static function hasResolvedValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return filled($value);
    }

    protected function resolveInheritedAssetPath(string $field): ?string
    {
        $configuredPath = trim((string) $this->getAttribute($field));

        if (filled($configuredPath)) {
            return $configuredPath;
        }

        $brandingTenant = $this->inheritedBrandingTenant();

        if (! $brandingTenant instanceof self) {
            return null;
        }

        $inheritedPath = trim((string) $brandingTenant->getAttribute($field));

        return filled($inheritedPath) ? $inheritedPath : null;
    }

    protected function publicAssetUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
