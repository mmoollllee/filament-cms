<?php

namespace Mmoollllee\Cms\Support\Branding;

use Mmoollllee\Cms\Contracts\Tenant;

/**
 * The site design tokens derived from a tenant's primary color.
 *
 * One source for two consumers, because both have to agree or editors preview
 * the wrong brand:
 * - the frontend layout writes them onto <body> ({@see \Mmoollllee\Cms\Support\Branding\SiteTokens::inlineStyle()})
 * - the Filament panel writes the same set into its <head>, so the RichEditor
 *   and the builder previews render in the SELECTED tenant's colors instead of
 *   whatever constant the app's stylesheet happens to ship
 *   ({@see \Mmoollllee\Cms\Filament\Providers\BasePanelProvider::panelPrimaryColorStyles()}).
 *
 * An app's own token file (the `@theme` block its CSS is built from) stays the
 * build-time default — for utility generation and for any context without a
 * tenant. At runtime these values win.
 */
class SiteTokens
{
    /**
     * key → value, ready to be written as CSS custom properties.
     *
     * The derived shades are `color-mix()` rather than fixed values so a tenant
     * only ever configures ONE color and the palette follows.
     *
     * @return array<string, string>
     */
    public static function forTenant(?Tenant $tenant): array
    {
        $primaryColor = $tenant?->resolvedPrimaryColor();

        if (blank($primaryColor)) {
            return [];
        }

        return [
            '--color-primary' => $primaryColor,
            '--color-surface' => "color-mix(in oklab, {$primaryColor} 78%, black 22%)",
            '--color-muted-text' => "color-mix(in oklab, {$primaryColor} 52%, white 48%)",
            '--color-on-light' => "color-mix(in oklab, {$primaryColor} 82%, black 18%)",
            '--background-image-gradient-primary' => "radial-gradient(circle at 50% 50%, color-mix(in oklab, {$primaryColor} 68%, white 32%) 0%, color-mix(in oklab, {$primaryColor} 82%, black 18%) 331%)",
            '--background-image-gradient-bright' => "radial-gradient(circle at 50% 50%, color-mix(in oklab, white 92%, {$primaryColor} 8%) 0%, color-mix(in oklab, white 78%, {$primaryColor} 22%) 211%)",
        ];
    }

    /**
     * For a `style` attribute — the frontend layout's <body>.
     */
    public static function inlineStyle(?Tenant $tenant): string
    {
        return collect(static::forTenant($tenant))
            ->map(fn (string $value, string $token): string => "{$token}: {$value};")
            ->implode(' ');
    }

    /**
     * For a stylesheet — the panel injects this into its <head>.
     */
    public static function cssBlock(?Tenant $tenant, string $selector = ':root'): string
    {
        $declarations = static::inlineStyle($tenant);

        return blank($declarations) ? '' : "{$selector} { {$declarations} }";
    }
}
