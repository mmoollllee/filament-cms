<?php

/*
 * A content type takes its address from ONE rule: its own namespace (urlPathPrefix) or
 * its place in the tree (allowedParentTypes). PathGenerator lets the prefix win, so a
 * blueprint declaring both hands the editor a parent Select that moves the breadcrumb and
 * the listing but has no effect whatsoever on the URL.
 *
 * That is a declaration mistake, not a runtime state, and it is refused where blueprints
 * are collected — not from the accessors. urlPathPrefix() is called per row by the
 * frontend resolver, the sitemap, the navigation and the redirect map, so asserting there
 * would turn one typo into a site-wide 500 on whichever request touched it first.
 */

use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\SiteExtension;
use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;

function blueprintDeclaring(?string $prefix, array $parentTypes): ContentBlueprint
{
    return new class($prefix, $parentTypes) extends ConfiguredContentBlueprint
    {
        public function __construct(?string $prefix, array $parentTypes)
        {
            $this->key = 'demo.type';
            $this->label = 'Demo';
            $this->defaultTemplate = 'content.page';
            $this->urlPathPrefix = $prefix;
            $this->allowedParentTypes = $parentTypes;
        }
    };
}

/** A registry whose only site extension offers $blueprint. */
function registryOffering(ContentBlueprint $blueprint): ContentBlueprintRegistry
{
    $extension = new class($blueprint) implements SiteExtension
    {
        public function __construct(protected ContentBlueprint $blueprint) {}

        public function siteKey(): string
        {
            return 'demo';
        }

        public function blueprints(): array
        {
            return [$this->blueprint];
        }

        public function resources(): array
        {
            return [];
        }
    };

    $extensions = new class($extension) extends SiteExtensionRegistry
    {
        public function __construct(protected SiteExtension $extension) {}

        public function forSite(?string $siteKey): array
        {
            return [$this->extension];
        }
    };

    return new ContentBlueprintRegistry($extensions);
}

it('refuses a blueprint that takes its address from two rules at once', function () {
    registryOffering(blueprintDeclaring('/ratgeber/', ['default.page']))->options();
})->throws(LogicException::class, 'declares urlPathPrefix AND allowedParentTypes');

it('allows either rule on its own', function () {
    expect(registryOffering(blueprintDeclaring('/ratgeber/', []))->options())
        ->toBe(['demo.type' => 'Demo'])
        ->and(registryOffering(blueprintDeclaring(null, ['default.page']))->options())
        ->toBe(['demo.type' => 'Demo']);
});

it('leaves the accessors free of the check, so the frontend never pays for it', function () {
    // urlPathPrefix() runs per row in ContentResolver, the sitemap, the navigation and the
    // redirect map. A contradiction there must not be able to 500 a public page.
    $blueprint = blueprintDeclaring('/ratgeber/', ['default.page']);

    expect($blueprint->urlPathPrefix())->toBe('/ratgeber/')
        ->and($blueprint->allowedParentTypes())->toBe(['default.page']);
});
