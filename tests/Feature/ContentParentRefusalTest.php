<?php

/*
 * The saving hook refuses a parent the rules rule out (ValidParent) for every writer, keyed to
 * `parent_id`. Pages without a parent field must still tell the editor: a revision restore and
 * the Duplizieren action say it as a notice instead of failing without a word.
 */

use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ContentRevisions;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ListContents;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

function refusalPage(Tenant $tenant, array $attributes): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        ...$attributes,
    ]);
}

it('restores nothing and says why when the revision names a parent now below the page', function () {
    $parent = refusalPage($this->tenant, ['title' => 'Eltern', 'path' => '/eltern']);
    $page = refusalPage($this->tenant, ['title' => 'Kind', 'path' => '/eltern/kind', 'parent_id' => $parent->id]);

    // The page moves to the root, then its former parent below it: the older revision now
    // names a parent inside the page's own subtree.
    $page->update(['parent_id' => null, 'path' => '/kind']);
    $parent->update(['parent_id' => $page->id]);
    $page->fresh()->stashDraft(['title' => 'Offener Entwurf']);

    Livewire::test(ContentRevisions::class, ['record' => $page->getKey()])
        ->call('restoreVersion');

    Notification::assertNotified('Fassung nicht wiederhergestellt');

    expect($page->fresh()->parent_id)->toBeNull()
        ->and($page->fresh()->path)->toBe('/kind')
        ->and($page->fresh()->hasDraft())->toBeTrue();
});

it('duplicates nothing and says why when the record hangs below a parent the rules refuse today', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Altlast-Ratgeber',
    ]);
    $page = refusalPage($this->tenant, ['title' => 'Unterseite', 'path' => '/unterseite']);

    // default.page lists only default.page as a parent type — this link predates the rule.
    DB::table('contents')->where('id', $page->id)->update(['parent_id' => $guide->id, 'path' => '/ratgeber/altlast-ratgeber/unterseite']);

    $count = Content::query()->count();

    Livewire::test(ListContents::class)
        ->callAction(TestAction::make('replicate')->table($page->fresh()), data: [
            'title' => 'Unterseite (Kopie)',
            'path' => '/ratgeber/altlast-ratgeber/unterseite-kopie',
        ]);

    Notification::assertNotified('Nicht dupliziert');

    expect(Content::query()->count())->toBe($count);
});
