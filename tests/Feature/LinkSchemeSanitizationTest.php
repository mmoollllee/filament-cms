<?php

/*
 * Every editorial link field in this CMS is free text — the link picker, the
 * LinkFields group, the hero CTA and the menu-builder's `url` column all accept
 * whatever is typed. Blade escapes HTML entities inside an href but never looks
 * at the SCHEME, so a stored `javascript:`/`data:` value fired on click for any
 * anonymous visitor.
 *
 * The guard is PayloadLink::safeUrl(): schemeless values (relative paths,
 * anchors) pass through — they are the common case — everything outside
 * ALLOWED_SCHEMES becomes null so the caller can drop the link.
 */

use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Support\Content\PayloadLink;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

it('rejects dangerous url schemes', function (string $url) {
    expect(PayloadLink::safeUrl($url))->toBeNull();
})->with([
    'javascript' => ['javascript:alert(1)'],
    'mixed case' => ['JaVaScRiPt:alert(1)'],
    'data uri' => ['data:text/html,<script>alert(1)</script>'],
    'vbscript' => ['vbscript:msgbox(1)'],
    'tab obfuscated' => ["java\tscript:alert(1)"],
    'entity obfuscated' => ['&#106;avascript:alert(1)'],
]);

it('passes through the link shapes editors actually use', function (string $url) {
    expect(PayloadLink::safeUrl($url))->toBe($url);
})->with([
    'relative path' => ['/mietpark'],
    'relative with query' => ['/mietpark?a=1&b=2'],
    'anchor' => ['#kontakt'],
    'path with anchor' => ['/pfad#anker'],
    'absolute https' => ['https://example.test/x'],
    'mailto' => ['mailto:info@example.test'],
    'tel' => ['tel:+4971234'],
]);

it('reports no url at all for a rejected link so the view skips it', function () {
    $link = PayloadLink::from(['link' => 'javascript:alert(1)', 'link_label' => 'Klick']);

    // hasUrl() gates the <a> in every consuming view — a rejected link must not
    // render as a dead anchor.
    expect($link->hasUrl())->toBeFalse()
        ->and($link->url)->toBeNull()
        ->and((string) $link->attributes())->not->toContain('javascript');
});

it('keeps rendering a legitimate payload link', function () {
    $link = PayloadLink::from([
        'link' => '/mietpark',
        'link_label' => 'Zum Mietpark',
        'link_class' => 'btn',
    ]);

    expect($link->hasUrl())->toBeTrue()
        ->and((string) $link->attributes())->toContain('href="/mietpark"')
        ->and($link->labelOr('Mehr'))->toBe('Zum Mietpark');
});

it('strips a dangerous scheme from menu links', function () {
    $menu = Menu::create([
        'name' => 'Header',
        'tenant_id' => $this->tenant->getKey(),
        'is_visible' => true,
    ]);
    $menu->menuItems()->create(['title' => 'Böse', 'url' => 'javascript:alert(1)', 'order' => 1]);
    $menu->menuItems()->create(['title' => 'Gut', 'url' => '/kontakt', 'order' => 2]);
    // Own location name — the workbench seeder already occupies 'header', which
    // is unique per tenant.
    $menu->locations()->create(['location' => 'probe-nav', 'tenant_id' => $this->tenant->getKey()]);

    // linksForLocation() caches forever; make sure we read this menu, not a
    // leftover from another test in the same run.
    Cache::flush();

    $links = Menu::linksForLocation('probe-nav', $this->tenant);

    // The rejected item stays in the navigation but points nowhere dangerous.
    expect(collect($links)->pluck('href')->all())->toBe(['/', '/kontakt']);
});
