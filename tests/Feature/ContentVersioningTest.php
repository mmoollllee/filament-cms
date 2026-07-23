<?php

/*
 * Content versioning (HasVersions on top of overtrue/laravel-versionable +
 * mansoor/filament-versionable):
 *
 * - every APPLIED change (create, save, restore) records a snapshot version,
 * - the draft workflow stays invisible to the history: stashing/discarding
 *   creates NO version, and `draft` never appears inside version contents,
 * - `sort` (table reordering) is equally silent,
 * - the revisions page restores an older state — discarding a pending draft
 *   so the restored content is what the editor continues on,
 * - the dashboard widget lists the tenant's own changes only.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ContentRevisions;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Widgets\RecentVersionsWidget;
use Overtrue\LaravelVersionable\Version;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

/**
 * A consumer content model that has NOT adopted HasVersions (pre-adoption
 * upgrade state) — mirrors the workbench Content minus versioning.
 */
class VersionlessContent extends \Illuminate\Database\Eloquent\Model implements \Mmoollllee\Cms\Contracts\Content
{
    use \Mmoollllee\Cms\Concerns\Content\AssignsCurrentTenant;
    use \Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug;
    use \Mmoollllee\Cms\Concerns\Content\HasPublishingStatus;
    use \Mmoollllee\Cms\Concerns\HasDraft;

    protected $table = 'contents';

    protected $fillable = [
        'tenant_id', 'parent_id', 'content_type', 'template', 'layout_preset_ids',
        'title', 'slug', 'path', 'visibility', 'publish_from', 'publish_until',
        'blocks', 'payload', 'references', 'meta', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => ContentVisibility::class,
            'publish_from' => 'datetime',
            'publish_until' => 'datetime',
            'layout_preset_ids' => 'array',
            'blocks' => 'array',
            'payload' => 'array',
            'references' => 'array',
            'meta' => 'array',
        ];
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}

function versionedPage(Tenant $tenant, string $path = '/versions-fixture'): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Erste Fassung',
        'path' => $path,
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
    ]);
}

it('records an initial version on create and a snapshot per applied change', function () {
    $page = versionedPage($this->tenant);

    expect($page->versions()->count())->toBe(1);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->fillForm(['title' => 'Zweite Fassung'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $page->fresh();

    expect($fresh->versions()->count())->toBe(2)
        ->and($fresh->lastVersion->contents['title'])->toBe('Zweite Fassung')
        // The applying admin is recorded as the version author.
        ->and($fresh->lastVersion->user?->email)->toBe('admin@example.test');
});

it('keeps the draft workflow out of the history: no versions from stash/discard, no draft in snapshots', function () {
    $page = versionedPage($this->tenant);

    // Stash + discard: the served content did not change — no versions.
    $page->stashDraft(['title' => 'Entwurfs-Fassung']);
    expect($page->versions()->count())->toBe(1);

    $page->discardDraft();
    expect($page->versions()->count())->toBe(1);

    // Even a full save with a pending stash never snapshots the stash itself.
    $page->fresh()->stashDraft(['title' => 'Bleibt Entwurf']);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->call('save')
        ->assertHasNoFormErrors();

    $page->fresh()->versions->each(function (Version $version): void {
        expect($version->contents)->not->toHaveKey('draft');
    });
});

it('does not version table reordering (sort is excluded)', function () {
    $page = versionedPage($this->tenant);

    $page->update(['sort' => 42]);

    expect($page->fresh()->versions()->count())->toBe(1);
});

it('restores an older version via the revisions page and discards a pending draft', function () {
    $page = versionedPage($this->tenant);

    $page->update(['title' => 'Zweite Fassung']);
    expect($page->versions()->count())->toBe(2);

    // A pending draft would re-overlay the restored state — restore discards it.
    $page->fresh()->stashDraft(['title' => 'Offener Entwurf']);

    Livewire::test(ContentRevisions::class, ['record' => $page->getKey()])
        ->assertOk()
        ->call('restoreVersion');

    $fresh = $page->fresh();

    expect($fresh->title)->toBe('Erste Fassung')
        ->and($fresh->hasDraft())->toBeFalse()
        // The restore itself is a new applied change → new version.
        ->and($fresh->versions()->count())->toBe(3);
});

it('shows the revisions header action once history exists', function () {
    $page = versionedPage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        // Only the initial version → nothing to compare yet.
        ->assertActionHidden('revisions');

    $page->update(['title' => 'Zweite Fassung']);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertActionVisible('revisions');
});

it('lists only the current tenant\'s changes in the dashboard widget', function () {
    $own = versionedPage($this->tenant);
    $own->update(['title' => 'Eigene Änderung']);

    $ownFragment = Fragment::create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Eigenes Fragment',
        'slug' => 'widget-fragment',
        'blocks' => [],
    ]);

    // Another tenant's change must never leak into this dashboard.
    $foreignTenant = Tenant::factory()->create(['site_key' => 'default', 'primary_domain' => 'foreign.test']);
    $foreign = versionedPage($foreignTenant, '/foreign-fixture');
    $foreign->update(['title' => 'Fremde Änderung']);

    // Collections via the morph relations — a bare versionable_id lookup
    // would compare ids across two different tables.
    $ownVersions = $own->versions->merge($ownFragment->versions);

    Livewire::test(RecentVersionsWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords($ownVersions)
        ->assertCanNotSeeTableRecords($foreign->versions);
});

it('removes the version history when a record is hard-deleted', function () {
    $page = versionedPage($this->tenant);
    $page->update(['title' => 'Zweite Fassung']);

    expect($page->versions()->count())->toBe(2);

    $pageId = $page->getKey();
    $page->delete();

    expect(Version::withTrashed()
        ->where('versionable_type', Content::class)
        ->where('versionable_id', $pageId)
        ->count())->toBe(0);
});

it('prunes to the configured keep count with force-deletion', function () {
    expect((int) config('versionable.keep_versions'))->toBe(50);

    config(['versionable.keep_versions' => 1]);

    $page = versionedPage($this->tenant);
    $page->update(['title' => 'Zweite Fassung']);
    $page->update(['title' => 'Dritte Fassung']);

    // Only the newest survives, and pruned rows are gone for real
    // (forceDeleteVersion) — not just soft-deleted.
    expect($page->versions()->count())->toBe(1)
        ->and(Version::withTrashed()
            ->where('versionable_type', Content::class)
            ->where('versionable_id', $page->getKey())
            ->count())->toBe(1);
});

it('refuses to restore when no older state exists, keeping the pending draft', function () {
    $page = versionedPage($this->tenant);
    $page->stashDraft(['title' => 'Offener Entwurf']);

    // Only the initial version exists — restoreVersion() is publicly callable
    // although the blade hides the button.
    Livewire::test(ContentRevisions::class, ['record' => $page->getKey()])
        ->assertOk()
        ->call('restoreVersion')
        ->assertNotified('Keine ältere Fassung vorhanden');

    $fresh = $page->fresh();

    expect($fresh->title)->toBe('Erste Fassung')
        ->and($fresh->hasDraft())->toBeTrue()
        ->and($fresh->versions()->count())->toBe(1);
});

it('renders the edit page safely for models WITHOUT HasVersions (pre-adoption consumers)', function () {
    // Simulate a consumer app that upgraded the package but has not adopted
    // HasVersions yet: swap in a versionless content model. The plugin's own
    // hidden() closure would call $record->versions() and fatal — our header
    // action must gate BEFORE that (Filament evaluates hidden() first).
    \Mmoollllee\Cms\Cms::useContentModel(VersionlessContent::class);

    $record = VersionlessContent::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ohne Versionierung',
        'path' => '/versionless',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
    ]);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertActionHidden('revisions');
});
