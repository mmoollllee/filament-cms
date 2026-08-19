<?php

/*
 * The tenant's design tokens are written twice — onto the frontend <body> and
 * into the panel's <head> — and the two must stay in step: the panel is where
 * editors judge how a page will look, so a builder preview in another tenant's
 * brand is a wrong answer, not a cosmetic detail.
 */

use Filament\Forms\Components\RichEditor;
use Illuminate\Support\Facades\Blade;
use Mmoollllee\Cms\Filament\RichEditor\HtmlPreservePlugin;
use Mmoollllee\Cms\Support\Branding\SiteTokens;
use Mmoollllee\Cms\Support\Content\RichText;
use Workbench\App\Models\Tenant;

it('derives the whole palette from the tenant primary color', function () {
    $tenant = Tenant::factory()->make(['primary_color' => '#0075a7']);

    expect(SiteTokens::forTenant($tenant))
        ->toHaveKeys([
            '--color-primary',
            '--color-surface',
            '--color-muted-text',
            '--color-on-light',
            '--background-image-gradient-primary',
            '--background-image-gradient-bright',
        ])
        ->and(SiteTokens::forTenant($tenant)['--color-primary'])->toBe('#0075a7')
        // Derived shades reference the same color, so one setting drives all of them.
        ->and(SiteTokens::forTenant($tenant)['--color-surface'])->toContain('#0075a7');
});

it('renders as an inline style for the frontend body', function () {
    $tenant = Tenant::factory()->make(['primary_color' => '#0075a7']);

    expect(SiteTokens::inlineStyle($tenant))
        ->toStartWith('--color-primary: #0075a7;')
        ->toContain('--color-surface: color-mix(in oklab, #0075a7 78%, black 22%);');
});

it('renders as a css block for the panel head', function () {
    $tenant = Tenant::factory()->make(['primary_color' => '#0075a7']);

    expect(SiteTokens::cssBlock($tenant))
        ->toStartWith(':root { --color-primary: #0075a7;')
        ->toEndWith('}')
        // Same declarations either way — that is the point of the shared source.
        ->toContain(SiteTokens::inlineStyle($tenant));
});

it('emits nothing without a tenant, so the app stylesheet keeps its default', function () {
    // No tenant = no opinion: contexts outside the multi-tenant frontend/panel
    // (mails, error pages) must fall back to the build-time tokens.
    expect(SiteTokens::forTenant(null))->toBe([])
        ->and(SiteTokens::inlineStyle(null))->toBe('')
        ->and(SiteTokens::cssBlock(null))->toBe('');

    // A tenant WITHOUT an own color is a different case: the branding cascade
    // hands down the branding tenant's color, so tokens are still emitted.
    expect(SiteTokens::inlineStyle(Tenant::factory()->create(['primary_color' => null])))
        ->toContain('--color-primary:');
});

it('writes the tokens onto the frontend body', function () {
    $tenant = Tenant::factory()->create(['primary_color' => '#0075a7']);

    $html = Blade::render('<x-site.layout :tenant="$tenant">content</x-site.layout>', ['tenant' => $tenant]);

    // Matched against the body TAG rather than one exact attribute string: what
    // this test is about is that the tokens reach the frontend body, not the
    // order or line-wrapping of the attributes around them.
    expect($html)->toMatch('/<body\b[^>]*\bstyle="--color-primary: #0075a7;/')
        // `site` stays pinned: every custom property in site/base.css hangs off
        // `.site`, so losing the class leaves the whole frontend unstyled while
        // the token assertion above would still pass.
        ->toMatch('/<body\b[^>]*\bclass="[^"]*\bsite\b/');
});

it('marks the frontend body with the tenant site key', function () {
    $tenant = Tenant::factory()->create(['site_key' => 'acme']);

    $html = Blade::render('<x-site.layout :tenant="$tenant">content</x-site.layout>', ['tenant' => $tenant]);

    // The hook a site-specific CSS rule scopes itself with. On <body>, because
    // the floating header and its flyout render outside the page shell and every
    // tenant of an install is served by the same bundle.
    expect($html)->toMatch('/<body\b[^>]*\bdata-site="acme"/');
});

it('writes the same tokens into the panel head', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $tenant->update(['primary_color' => '#0075a7']);

    // The editor and the builder previews inside the panel are styled by the
    // app's frontend CSS; without this the tokens stay at the build-time value.
    $this->get('http://127.0.0.1/panel')
        ->assertOk()
        ->assertSee('--color-primary: #0075a7;', escape: false);
});

it('leaves rendered content markup untouched', function () {
    // The tokens are a styling layer only — no change to what RichText emits.
    expect(RichText::render('<p>Text</p>'))->toBe('<p>Text</p>');
});

it('marks the editor surface as rich-text content', function () {
    // The app's site CSS styles content through `.richtext`; the block previews
    // already carry it, so the editing surface has to as well or typography stops
    // at the preview. The JS extension puts it on the ProseMirror element —
    // extraInputAttributes() would put it on the panels container instead.
    actingAsMarketingPanelAdmin();

    expect(implode(' ', app(HtmlPreservePlugin::class)->getTipTapJsExtensions()))
        ->toContain('rich-text-surface')
        ->and(RichEditor::make('content')->getExtraInputAttributes())
        ->not->toHaveKey('class');
});
