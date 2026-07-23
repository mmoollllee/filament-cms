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
