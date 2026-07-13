<?php

namespace Mmoollllee\Cms\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract implemented by an application's tenant model (the concrete class is
 * resolved via Cms::tenantModel()).
 *
 * The engine reads the `site_key` attribute at runtime; this contract pins the
 * domain methods the engine/middleware/policies call explicitly. Auth-related
 * methods take the framework Authenticatable contract (not a concrete User).
 */
interface Tenant
{
    /** Human-readable brand/display name, used as a navigation fallback label. */
    public function displayName(): string;

    /** Whether the given (possibly null/guest) user may see this tenant. */
    public function isVisibleTo(?Authenticatable $user): bool;

    /** Whether the user belongs to this tenant (or is a superadmin over it). */
    public function hasUser(?Authenticatable $user): bool;

    /** Tagline/claim shown alongside the brand name (null when unset). */
    public function resolvedBrandClaim(): ?string;

    /** Public URL of the primary (main) logo, with branding inheritance. */
    public function resolvedMainLogoUrl(): ?string;

    /** Public URL of the secondary logo, with branding inheritance. */
    public function resolvedSecondaryLogoUrl(): ?string;

    /** Dedicated raster e-mail logo path, with branding inheritance (null when unset). */
    public function resolvedMailLogoPath(): ?string;

    /** Mail-client-safe (raster, absolute) logo URL; null → fall back to text. */
    public function resolvedMailLogoUrl(): ?string;

    /** Public URL of the favicon, with branding inheritance (null when unset). */
    public function resolvedFaviconUrl(): ?string;

    /** Public URL of the default Open Graph image, with branding inheritance. */
    public function resolvedDefaultOgImageUrl(): ?string;

    /** Computed default SEO/document title for this tenant. */
    public function resolvedDefaultSeoTitle(): string;

    /** Frontend document title for a content record (null → the tenant default). */
    public function frontendTitleFor(?Content $content = null): string;

    /** Frontend document title for a raw title/path pair (unsaved form state). */
    public function frontendTitleForValues(?string $title, ?string $path): string;

    /** Default SEO meta description (null when unset). */
    public function resolvedDefaultSeoDescription(): ?string;

    /** Effective primary brand color (hex), falling back to the default. */
    public function resolvedPrimaryColor(): string;

    /** Resolve a branding/site setting attribute, applying inheritance + default. */
    public function resolvedSiteSetting(string $field, mixed $default = null): mixed;

    /**
     * Social links prepared for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolvedSocialLinksForDisplay(): array;
}
