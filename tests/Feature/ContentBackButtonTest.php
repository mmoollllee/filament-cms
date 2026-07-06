<?php

use Mmoollllee\Cms\Contracts\Content as ContentContract;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Http\Controllers\Frontend\ContentShowController;
use Mmoollllee\Cms\Http\Controllers\Frontend\OnepagerShellController;
use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Mmoollllee\Cms\Support\Content\TemplateResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * The standalone "back" button is no longer hardcoded to app-specific content types: a blueprint
 * contributes its own target via ContentBlueprint::backButton(); otherwise the controller falls
 * back to a generic default (parent overview → homepage).
 */

/** A registry stub whose find() always returns the given blueprint (or null). */
function backButtonRegistry(?ContentBlueprint $stub): ContentBlueprintRegistry
{
    $registry = new class(app(SiteExtensionRegistry::class)) extends ContentBlueprintRegistry
    {
        public ?ContentBlueprint $stub = null;

        public function find(string $key, ?string $siteKey = null): ?ContentBlueprint
        {
            return $this->stub;
        }
    };

    $registry->stub = $stub;

    return $registry;
}

/** The real controller with real collaborators, but the given (stub) blueprint registry. */
function backButtonController(ContentBlueprintRegistry $registry): ContentShowController
{
    return new class(
        app(CurrentTenant::class),
        app(ContentResolver::class),
        app(NavigationContextBuilder::class),
        app(TemplateResolver::class),
        app(LayoutPresetResolver::class),
        app(OnepagerShellController::class),
        $registry,
    ) extends ContentShowController
    {
        public function backButtonFor(ContentContract $content): ?array
        {
            return $this->resolveBackButton($content);
        }
    };
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('has no blueprint back button by default', function () {
    $blueprint = new class extends ConfiguredContentBlueprint {};

    expect($blueprint->backButton(Content::factory()->create()))->toBeNull();
});

it('uses the blueprint-provided back button when present', function () {
    $blueprint = new class extends ConfiguredContentBlueprint
    {
        public function backButton(ContentContract $content): ?array
        {
            return ['href' => '/blog', 'label' => 'Zurück zur Übersicht'];
        }
    };

    $content = Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Mein Artikel',
        'path' => '/blog/mein-artikel',
    ]);

    expect(backButtonController(backButtonRegistry($blueprint))->backButtonFor($content))
        ->toBe(['href' => '/blog', 'label' => 'Zurück zur Übersicht']);
});

it('returns no back button on the homepage', function () {
    $content = Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Start',
        'path' => '/',
    ]);

    expect(backButtonController(backButtonRegistry(null))->backButtonFor($content))->toBeNull();
});

it('falls back to the parent overview when no blueprint override', function () {
    $parent = Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Projekte',
        'path' => '/projekte',
    ]);
    $child = Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Kanalbau',
        'path' => '/projekte/kanalbau',
        'parent_id' => $parent->id,
    ]);

    expect(backButtonController(backButtonRegistry(null))->backButtonFor($child))
        ->toBe(['href' => '/projekte', 'label' => 'Zurück zur Übersicht']);
});

it('falls back to the homepage for a top-level page without override', function () {
    $content = Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Kontakt',
        'path' => '/kontakt',
    ]);

    expect(backButtonController(backButtonRegistry(null))->backButtonFor($content))
        ->toBe(['href' => '/', 'label' => 'Zurück zur Startseite']);
});
