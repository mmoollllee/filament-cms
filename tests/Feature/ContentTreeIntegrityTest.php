<?php

/*
 * The tree's invariants hold for every writer, and every walk survives the rows that slip
 * past them anyway — an import, a raw query:
 *
 * - a parent of another tenant, one below the record itself, or one of a type the
 *   blueprint rules out is refused on save, not only left out of the parent Select;
 * - a type that lists no parent types (a urlPathPrefix type) may still be filed under a
 *   page — sites use that for structure the URL never reads;
 * - the breadcrumbs, the onepager lookup and the path generator ignore a foreign parent,
 *   and end at a cycle instead of looping until the request dies;
 * - a save and the subtree it moves are one write, outside the panel too.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Content\ContentTree;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['site_key' => 'marketing']);
    $this->foreign = Tenant::factory()->create(['site_key' => 'acme']);
    app(CurrentTenant::class)->set($this->tenant);
});

function integrityPage(Tenant $tenant, array $attributes): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
        ...$attributes,
    ]);
}

/** Fails the test instead of letting a runaway walk take the process down. */
function failAfterQueries(int $limit): void
{
    $count = 0;

    DB::listen(function () use (&$count, $limit): void {
        if (++$count > $limit) {
            throw new RuntimeException("runaway: more than {$limit} queries");
        }
    });
}

it('refuses a parent of another tenant, whoever saves', function () {
    $foreignParent = integrityPage($this->foreign, ['title' => 'Fremd', 'path' => '/fremd']);

    expect(fn () => integrityPage($this->tenant, ['title' => 'Eigen', 'path' => '/eigen', 'parent_id' => $foreignParent->id]))
        ->toThrow(ValidationException::class, 'Die übergeordnete Seite gibt es auf dieser Website nicht.');

    expect(Content::query()->where('title', 'Eigen')->exists())->toBeFalse();
});

it('refuses a parent below the record itself — a restored revision can name one', function () {
    $a = integrityPage($this->tenant, ['title' => 'A', 'path' => '/a']);
    $b = integrityPage($this->tenant, ['title' => 'B', 'path' => '/a/b', 'parent_id' => $a->id]);

    expect(fn () => $a->update(['parent_id' => $b->id]))
        ->toThrow(ValidationException::class, 'Eine Seite kann nicht unter sich selbst oder einer ihrer Unterseiten liegen.')
        ->and(fn () => $a->fresh()->update(['parent_id' => $a->id]))
        ->toThrow(ValidationException::class, 'Eine Seite kann nicht unter sich selbst oder einer ihrer Unterseiten liegen.');

    expect($a->fresh()->parent_id)->toBeNull();
});

it('refuses a parent type the blueprint does not list', function () {
    // default.page lists default.page as its only parent type.
    $section = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.section',
        'title' => 'Bereich',
    ]);

    expect(fn () => integrityPage($this->tenant, ['title' => 'Seite', 'path' => '/seite', 'parent_id' => $section->id]))
        ->toThrow(ValidationException::class);
});

it('files a type that lists no parent types under any page of its tenant', function () {
    // marketing.guide owns its address through its urlPathPrefix and lists no parent
    // types. Sites still hang such records below a listing for structure.
    $section = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.section',
        'title' => 'Ratgeber-Bereich',
    ]);

    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'parent_id' => $section->id,
        'title' => 'Erste Hilfe',
    ]);

    expect($guide->parent_id)->toBe($section->id)
        ->and($guide->path)->toBe('/ratgeber/erste-hilfe');
});

it('still moves a legacy row whose parent the rule would refuse today', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Alt',
    ]);

    // default.page lists only default.page as a parent type — this link predates the
    // rule. The cascade re-saves the row without touching its parent, so it is not asked.
    $page = integrityPage($this->tenant, ['title' => 'Unterseite', 'path' => '/unterseite']);
    DB::table('contents')->where('id', $page->id)->update(['parent_id' => $guide->id, 'path' => '/ratgeber/alt/unterseite']);

    $guide->update(['path' => '/ratgeber/neu']);

    expect($page->fresh()->path)->toBe('/ratgeber/neu/unterseite');
});

it('keeps a foreign parent out of the path, the lookup and the breadcrumbs', function () {
    $foreignParent = integrityPage($this->foreign, ['title' => 'Fremde Seite', 'path' => '/fremd']);
    $adopted = integrityPage($this->tenant, ['title' => 'Adoptiert', 'path' => '/adoptiert']);

    // Written past the hooks, the way an import or a raw query still can.
    DB::table('contents')->where('id', $adopted->id)->update(['parent_id' => $foreignParent->id]);

    // An eager load does not scope the relation; the generator checks it anyway.
    $eager = Content::query()->with('parent')->find($adopted->id);

    expect($eager->generatedPath())->toBe('/adoptiert')
        ->and(app(ContentResolver::class)->findByPath($this->tenant, '/fremd/adoptiert'))->toBeNull()
        ->and(app(NavigationContextBuilder::class)->breadcrumbLabelsFor($adopted->fresh()))->toBe(['Adoptiert']);
});

it('ends every walk up the tree at a cycle', function () {
    $a = integrityPage($this->tenant, ['title' => 'A', 'path' => '/zyklus-a']);
    $b = integrityPage($this->tenant, ['title' => 'B', 'path' => '/zyklus-a/b', 'parent_id' => $a->id]);

    // Only reachable past the hooks — which is exactly how a broken import leaves it.
    DB::table('contents')->where('id', $a->id)->update(['parent_id' => $b->id]);

    failAfterQueries(50);

    expect(app(NavigationContextBuilder::class)->breadcrumbLabelsFor($b->fresh()))->toBe(['A', 'B'])
        ->and(app(ContentResolver::class)->onepagerSectionFor($b->fresh(), $this->tenant))->toBeNull()
        ->and($b->fresh()->generatedPath())->toBe('/zyklus-a/b');
});

it('ends a subtree cascade at a cycle instead of re-saving its rows into each other', function () {
    $a = integrityPage($this->tenant, ['title' => 'A', 'path' => '/zyklus-a']);
    $b = integrityPage($this->tenant, ['title' => 'B', 'path' => '/zyklus-a/b', 'parent_id' => $a->id]);

    // Only reachable past the hooks — which is exactly how a broken import leaves it.
    DB::table('contents')->where('id', $a->id)->update(['parent_id' => $b->id]);

    failAfterQueries(300);

    // The save composes A below B and drags B along — once, not back into A.
    expect($a->fresh()->save())->toBeTrue()
        ->and($a->fresh()->path)->toBe('/zyklus-a/b/zyklus-a')
        ->and($b->fresh()->path)->toBe('/zyklus-a/b/zyklus-a/b');
});

it('reads only the subtree it walks, one level per query', function () {
    $root = integrityPage($this->tenant, ['title' => 'Wurzel', 'path' => '/wurzel']);
    $child = integrityPage($this->tenant, ['title' => 'Kind', 'path' => '/wurzel/kind', 'parent_id' => $root->id]);
    $grandchild = integrityPage($this->tenant, ['title' => 'Enkel', 'path' => '/wurzel/kind/enkel', 'parent_id' => $child->id]);

    // The rest of the tenant, and a foreign row that names the root as its parent.
    foreach (range(1, 3) as $number) {
        integrityPage($this->tenant, ['title' => "Andere {$number}", 'path' => "/andere-{$number}"]);
    }

    $foreign = integrityPage($this->foreign, ['title' => 'Fremd', 'path' => '/fremd']);
    DB::table('contents')->where('id', $foreign->id)->update(['parent_id' => $root->id]);

    DB::enableQueryLog();

    $keys = app(ContentTree::class)->subtreeKeys($root);

    // A rename asks this for every row its cascade re-saves: each query binds the one level
    // it reads — never the whole tenant, never the keys collected so far.
    expect(array_map('intval', $keys))->toBe([$root->id, $child->id, $grandchild->id])
        ->and(array_map(fn (array $query): array => array_map('intval', $query['bindings']), DB::getQueryLog()))->toBe([
            [$this->tenant->id, $root->id],
            [$this->tenant->id, $child->id],
            [$this->tenant->id, $grandchild->id],
        ]);
});

it('saves a move and the subtree it drags along as one write, outside the panel too', function () {
    $parent = integrityPage($this->tenant, ['title' => 'Eltern', 'path' => '/eltern-x']);
    integrityPage($this->tenant, ['title' => 'Kind, das scheitert', 'path' => '/eltern-x/kind', 'parent_id' => $parent->id]);

    // A descendant that fails to save once its parent has already been written.
    Content::saving(function (Content $content): void {
        if ($content->title === 'Kind, das scheitert' && $content->path === '/eltern-y/kind') {
            throw new RuntimeException('child save failed');
        }
    });

    expect(fn () => $parent->fresh()->update(['path' => '/eltern-y']))
        ->toThrow(RuntimeException::class, 'child save failed');

    // Nothing of the move survives: not the parent's UPDATE, not its redirect.
    expect($parent->fresh()->path)->toBe('/eltern-x')
        ->and(Content::query()->where('title', 'Kind, das scheitert')->value('path'))->toBe('/eltern-x/kind')
        ->and(Redirect::query()->where('from_path', '/eltern-x')->exists())->toBeFalse();
});
