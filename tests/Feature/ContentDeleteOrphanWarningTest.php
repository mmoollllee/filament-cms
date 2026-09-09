<?php

/*
 * `parent_id` is nullOnDelete. Deleting a page therefore re-parents its children to the
 * root, and they KEEP their address: PathGenerator hands back a stored path in full once
 * no prefix and no parent own any of it, so re-saving them changes nothing. The page goes
 * on living under a namespace that no longer belongs to anything, and the drift check
 * cannot see it because that path is a fixpoint of the generator.
 *
 * The delete confirmation says so first, in the same words whichever button was reached
 * for. The wording is asked of the action itself rather than of rendered HTML: Filament
 * streams a modal's body on open, so it is not in the page the mount returns.
 */

use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    $this->page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ratgeber',
        'path' => '/ratgeber',
    ]);
});

/** What the delete confirmation for $record would say. */
function deleteWarningFor(Content $record): ?string
{
    return TenantScopedContentResource::warnAboutOrphans(DeleteAction::make())
        ->record($record)
        ->getModalDescription();
}

function addChild(Content $parent, string $title, string $path): Content
{
    return Content::create([
        'tenant_id' => $parent->tenant_id,
        'content_type' => 'default.page',
        'parent_id' => $parent->id,
        'title' => $title,
        'path' => $path,
    ]);
}

it('names the page a delete would strand at the root', function () {
    addChild($this->page, 'Erste Schritte', '/ratgeber/erste-schritte');

    expect(deleteWarningFor($this->page))
        ->toContain('Darunter liegt noch eine Seite')
        ->toContain('Erste Schritte')
        ->toContain('behält ihre Adresse');
});

it('promises only what the generator actually does to a stranded page', function () {
    $child = addChild($this->page, 'Erste Schritte', '/ratgeber/erste-schritte');

    $this->page->delete();

    // The claim in the copy, measured: the address does NOT move on the next save.
    $child->fresh()->save();

    expect($child->fresh()->path)->toBe('/ratgeber/erste-schritte')
        ->and($child->fresh()->parent_id)->toBeNull();
});

it('counts the whole set rather than listing it without end', function () {
    foreach (range(1, 7) as $index) {
        addChild($this->page, "Kapitel {$index}", "/ratgeber/kapitel-{$index}");
    }

    expect(deleteWarningFor($this->page))
        ->toContain('Darunter liegen noch 7 Seiten')
        ->toContain('und 2 weitere');
});

it("keeps Filament's own wording for a page with nothing underneath", function () {
    expect(deleteWarningFor($this->page))->toBe(__('filament-actions::modal.confirmation'));
});

it('says nothing about a grandchild, which keeps the parent it has', function () {
    $child = addChild($this->page, 'Erste Schritte', '/ratgeber/erste-schritte');
    addChild($child, 'Kontakt', '/ratgeber/erste-schritte/kontakt');

    // Only the direct children are re-parented by the foreign key; the rest follow on the
    // next cascade, so promising anything about them here would be a guess.
    expect(deleteWarningFor($this->page))
        ->toContain('Darunter liegt noch eine Seite')
        ->not->toContain('Kontakt');
});

it('ignores a page of another tenant that points here', function () {
    // The seeder's second tenant — a real neighbour, not a fixture invented for this.
    $foreign = Tenant::query()->where('site_key', 'acme')->sole();

    Content::create([
        'tenant_id' => $foreign->id,
        'content_type' => 'default.page',
        'parent_id' => $this->page->id,
        'title' => 'Fremde Seite',
        'path' => '/fremde-seite',
    ]);

    // Nothing validates parent_id against a tenant, and this delete does not move that row.
    expect(deleteWarningFor($this->page))->toBe(__('filament-actions::modal.confirmation'));
});

it('warns on the footer button too, not only on the row action', function () {
    addChild($this->page, 'Erste Schritte', '/ratgeber/erste-schritte');

    $page = Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])->instance();

    // getDeleteFormAction() is the page's own template method — reached here directly
    // because Filament does not register form actions under a name a test can mount.
    $action = (fn (): DeleteAction => $this->getDeleteFormAction())->call($page);

    expect($action->record($this->page)->getModalDescription())
        ->toContain('Erste Schritte');
});
