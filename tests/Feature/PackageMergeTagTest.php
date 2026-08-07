<?php

/*
 * Packages that plug into the CMS (filament-consent-control's cookie-settings
 * button being the first) contribute a merge tag to whatever list the app runs
 * with. Shortcodes::useMergeTags() REPLACES the label list — an app curating its
 * own labels must not have to know about every installed package, so package
 * tags are merged on top instead.
 */

use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\Support\Content\RichText;
use Mmoollllee\Cms\Support\Shortcodes;

beforeEach(fn () => Shortcodes::reset());
afterEach(fn () => Shortcodes::reset());

function registerPackageMergeTag(): void
{
    // How a package registers: inside the extension hook, so it survives the
    // Shortcodes::reset() that tests (and long-lived runtimes) trigger.
    Shortcodes::extendDefaultsUsing(function (): void {
        Shortcodes::register('package_tag', fn (array $attributes): string => '<button class="pkg">'.($attributes['label'] ?? 'Standard').'</button>');

        Shortcodes::registerMergeTag(
            'package_tag',
            'Paket-Tag',
            fn (): HtmlString => new HtmlString('<button class="pkg">Standard</button>'),
        );
    });
}

it('shows a package merge tag in the picker without any app wiring', function () {
    registerPackageMergeTag();

    expect(Shortcodes::mergeTags())
        ->toHaveKey('package_tag')
        // …alongside the defaults, which the app has not replaced here.
        ->toHaveKey('company_name')
        ->and(Shortcodes::mergeTags()['package_tag'])->toBe('Paket-Tag');
});

it('keeps the package tag when the app replaces the label list', function () {
    registerPackageMergeTag();

    Shortcodes::useMergeTags(['company_name' => 'Firmenname']);

    expect(Shortcodes::mergeTags())
        ->toBe(['company_name' => 'Firmenname', 'package_tag' => 'Paket-Tag']);
});

it('renders the package tag through the rich-text pipeline', function () {
    registerPackageMergeTag();

    // Merge tag: the RichEditor stores a mergeTag node the renderer replaces.
    $fromMergeTag = RichText::render(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'mergeTag', 'attrs' => ['id' => 'package_tag']]]],
    ]]);

    // Shortcode: the same key typed into any rich text, attributes included.
    $fromShortcode = RichText::render('<p>[package_tag label="Eigenes"]</p>');

    expect($fromMergeTag)->toContain('<button class="pkg">Standard</button>')
        ->and($fromShortcode)->toContain('<button class="pkg">Eigenes</button>');
});

it('registers the value alongside the label', function () {
    registerPackageMergeTag();

    expect(Shortcodes::mergeTagValues())->toHaveKey('package_tag');
});

it('still picks up a package that registers after the first render', function () {
    // Provider boot order is nobody's contract: a package may register its tag
    // after something already rendered a shortcode (which boots the registry).
    Shortcodes::render('[company_name]');

    registerPackageMergeTag();

    expect(Shortcodes::mergeTags())->toHaveKey('package_tag')
        ->and(Shortcodes::mergeTagValues())->toHaveKey('package_tag');
});
