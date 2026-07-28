<?php

/*
 * resources/css/versionable.css is a hand-precompiled copy of the
 * filament-versionable plugin's Tailwind-source CSS (the CMS panels run
 * without a custom vite theme, so @apply is unavailable). Same convention as
 * FilamentViewOverrideDriftTest: pin the vendor original's hash so a plugin
 * update that changes the diff styles fails LOUDLY here instead of shipping
 * unstyled revisions diffs to every consumer panel.
 *
 * On failure: diff vendor/mansoor/filament-versionable/resources/css/plugin.css
 * against the previous baseline, re-derive resources/css/versionable.css
 * (translate @apply to plain CSS, keep the .dark variants), then update the
 * baseline hash below.
 */

const VERSIONABLE_PLUGIN_CSS_BASELINE = 'fc7a473f5496fe9c57edb64d76028bfb';

it('pins the vendor plugin.css the precompiled versionable.css was derived from', function () {
    $vendorCss = base_path('vendor/mansoor/filament-versionable/resources/css/plugin.css');

    expect(md5_file($vendorCss))->toBe(VERSIONABLE_PLUGIN_CSS_BASELINE);
});

/*
 * The precompiled CSS above only covers the diff TABLE. The page around it is the
 * plugin's own Blade view, whose Tailwind utilities exist in a consumer panel only
 * while filament-cms.css points a @source at the plugin's views — drop that and the
 * revisions page loses its whole grid/spacing layout, silently and only in the build.
 */
it('registers the plugin views as a tailwind source of the theme glue', function () {
    $cssDirectory = dirname(__DIR__, 2).'/resources/css';

    preg_match_all(
        "/@source '([^']*mansoor\/filament-versionable[^']*)';/",
        (string) file_get_contents($cssDirectory.'/filament-cms.css'),
        $matches,
    );

    expect($matches[1])->not->toBeEmpty();

    // @source resolves against the real path of the CSS file, which differs between a
    // vendor install and a symlinked path repo — one candidate has to hit the views.
    $scannedViews = collect($matches[1])
        ->map(fn (string $glob): string|false => realpath($cssDirectory.'/'.strstr($glob, '**', before_needle: true)))
        ->filter()
        ->filter(fn (string $directory): bool => file_exists($directory.'/views/revisions-page.blade.php'));

    expect($scannedViews)->not->toBeEmpty();
});
