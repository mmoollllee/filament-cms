<?php

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Cms\Models\Menu;
use Workbench\App\Models\Tenant;

/**
 * Menu::linksForLocation() is the only view a frontend gets of a menu item, so
 * whatever it drops is information the theme has to invent. It used to drop the
 * item's presentation metadata, which left "style it like a call to action" to
 * be guessed from the href's host — a guess that quietly claims every external
 * link a site adds later. These pin that the editor's own choice survives.
 */
it('carries the item presentation metadata into the link array', function () {
    $tenant = Tenant::factory()->create();

    makeHeaderMenu($tenant, [
        'title' => 'Jobs',
        'url' => 'https://jobs.example.test',
        'target' => '_blank',
        'rel' => 'noopener',
        'classes' => 'flyout-btn--cta',
        'icon' => 'heroicon-o-briefcase',
    ]);

    expect(Menu::linksForLocation('header', $tenant)[0])->toMatchArray([
        'label' => 'Jobs',
        'href' => 'https://jobs.example.test',
        'target' => '_blank',
        'rel' => 'noopener',
        'classes' => 'flyout-btn--cta',
        'icon' => 'heroicon-o-briefcase',
    ]);
});

it('passes an unset metadata field through as the menu builder stores it', function () {
    $tenant = Tenant::factory()->create();

    makeHeaderMenu($tenant, ['title' => 'Start', 'url' => '/']);

    // `target` carries the plugin's own column default rather than null — passed
    // through faithfully instead of being second-guessed here, and harmless in
    // markup since `_self` is what a link does anyway.
    expect(Menu::linksForLocation('header', $tenant)[0])->toMatchArray([
        'target' => '_self',
        'rel' => null,
        'classes' => null,
        'icon' => null,
    ]);
});

/**
 * The icon is a free-text field in the menu builder and the icon sets configure
 * no fallback, so an unresolvable name would throw SvgNotFound on every page
 * that renders the menu — not just the one being edited. Navigation decoration
 * must not be able to take a site down.
 */
it('renders a known menu icon', function () {
    expect(Blade::render('<x-site.menu-icon name="heroicon-o-briefcase" class="size-4" />'))
        ->toContain('<svg');
});

it('drops an unknown menu icon instead of throwing', function () {
    expect(trim(Blade::render('<x-site.menu-icon name="icon-does-not-exist" />')))->toBe('');
});

it('renders nothing without a name', function () {
    expect(trim(Blade::render('<x-site.menu-icon />')))->toBe('');
});
