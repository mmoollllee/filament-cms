<?php

use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Models\Menu;
use Workbench\App\Models\Tenant;

/**
 * Child items an editor nests in the menu builder are opt-in
 * (Cms::enableNestedMenus()): off, the link arrays keep the flat shape every
 * existing theme renders; on, each top-level entry carries one level of
 * `children` and the fallback flyout renders them below their parent.
 */
function makeNestedHeaderMenu(Tenant $tenant): Menu
{
    $menu = makeHeaderMenu($tenant, ['title' => 'Über uns', 'url' => '/ueber-uns']);
    $parent = $menu->menuItems()->first();

    $child = $menu->menuItems()->create(['parent_id' => $parent->id, 'title' => 'Stellenangebote', 'url' => '/ueber-uns/stellenangebote', 'order' => 0]);
    $menu->menuItems()->create(['parent_id' => $child->id, 'title' => 'Ausbildung', 'url' => '/ueber-uns/stellenangebote/ausbildung', 'order' => 0]);
    $menu->menuItems()->create(['title' => 'Kontakt', 'url' => '/kontakt', 'order' => 1]);

    return $menu;
}

it('keeps the flat link shape and drops child items by default', function () {
    $tenant = Tenant::factory()->create();
    makeNestedHeaderMenu($tenant);

    $links = Menu::linksForLocation('header', $tenant);

    expect($links)->toHaveCount(2)
        ->and($links[0])->not->toHaveKey('children')
        ->and(collect($links)->pluck('label')->all())->toBe(['Über uns', 'Kontakt']);
});

it('carries one level of child items when nested menus are enabled', function () {
    Cms::enableNestedMenus();

    $tenant = Tenant::factory()->create();
    makeNestedHeaderMenu($tenant);

    $links = Menu::linksForLocation('header', $tenant);

    expect($links)->toHaveCount(2)
        ->and($links[1]['children'])->toBe([])
        ->and($links[0]['children'])->toHaveCount(1)
        ->and($links[0]['children'][0])->toMatchArray([
            'label' => 'Stellenangebote',
            'href' => '/ueber-uns/stellenangebote',
            'target' => '_self',
        ])
        // Only one level deep: the grandchild is dropped, not flattened in.
        ->and($links[0]['children'][0])->not->toHaveKey('children');
});

it('scheme-checks child item urls like top-level ones', function () {
    Cms::enableNestedMenus();

    $tenant = Tenant::factory()->create();
    $menu = makeHeaderMenu($tenant);
    $menu->menuItems()->create(['parent_id' => $menu->menuItems()->first()->id, 'title' => 'Evil', 'url' => 'javascript:alert(1)', 'order' => 0]);

    expect(Menu::linksForLocation('header', $tenant)[0]['children'][0]['href'])->toBe('/');
});

it('reflects an added child item without a stale cache', function () {
    Cms::enableNestedMenus();

    $tenant = Tenant::factory()->create();
    $menu = makeHeaderMenu($tenant);

    expect(Menu::linksForLocation('header', $tenant)[0]['children'])->toBe([]);

    $menu->menuItems()->create(['parent_id' => $menu->menuItems()->first()->id, 'title' => 'Team', 'url' => '/team', 'order' => 0]);

    expect(Menu::linksForLocation('header', $tenant)[0]['children'])->toHaveCount(1);
});

it('does not serve the flat cached shape after opting in', function () {
    $tenant = Tenant::factory()->create();
    makeNestedHeaderMenu($tenant);

    // Warm the flat cache first — a shared key would keep serving it.
    expect(Menu::linksForLocation('header', $tenant)[0])->not->toHaveKey('children');
    expect(Cache::has("tenant:{$tenant->id}:menu:header"))->toBeTrue();

    Cms::enableNestedMenus();

    expect(Menu::linksForLocation('header', $tenant)[0]['children'])->toHaveCount(1);
});

it('renders child items below their parent in the fallback flyout', function () {
    $link = fn (string $label, string $path, array $extra = []): array => [
        'path' => $path, 'href' => $path, 'label' => $label,
        'target' => '_self', 'rel' => null, 'classes' => null, 'icon' => null,
        ...$extra,
    ];

    $flyout = view('cms::partials.header-flyout', [
        'sectionLinks' => [
            $link('Über uns', '/ueber-uns', ['children' => [$link('Stellenangebote', '/ueber-uns/stellenangebote')]]),
            $link('Kontakt', '/kontakt'),
        ],
        'socialLinks' => [],
        'legalLinks' => [],
    ])->render();

    expect($flyout)->toContain('class="flyout-btn flyout-btn--child"')
        ->and(substr_count($flyout, 'flyout-btn--child'))->toBe(1)
        // A parent is active for its whole section, a child only on its own page.
        ->and($flyout)->toContain("currentNavigationRootPath() === '\\/ueber-uns'")
        ->and($flyout)->toContain("currentNavigationPath() === '\\/ueber-uns\\/stellenangebote'")
        // The parent of a nested entry is the current section, never a second current page.
        ->and($flyout)->toContain("currentNavigationPath() === '\\/ueber-uns' ? 'page' : (currentNavigationRootPath() === '\\/ueber-uns' ? 'true' : 'false')")
        ->and($flyout)->toContain("currentNavigationRootPath() === '\\/kontakt' ? 'page' : 'false'")
        ->and(strpos($flyout, 'Über uns'))->toBeLessThan(strpos($flyout, 'Stellenangebote'))
        ->and(strpos($flyout, 'Stellenangebote'))->toBeLessThan(strpos($flyout, 'Kontakt'));
});
