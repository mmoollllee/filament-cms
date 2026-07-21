<?php

/*
 * "Als Entwurf anlegen" on the create pages (CreatesDrafts):
 *
 * - Runs the normal create() pipeline, but neutralizes the applied row
 *   (content: publishing window emptied → unpublished; fragment: no active
 *   blocks → renders nowhere) and stashes the FULL form state as a pending
 *   draft — the edit page then continues directly in the draft workflow.
 * - The classic "Erstellen" stays untouched (no stash).
 * - The button pair renders in the footer and mirrors into the header.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Fragments\Pages\CreateFragment;
use Mmoollllee\Cms\Filament\Resources\Fragments\Pages\EditFragment;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

it('creates content as draft: applied row unpublished, entered window stashed', function () {
    // The pickers run with seconds(false) — form state dehydrates minute-precise.
    $publishFrom = now()->subHour()->format('Y-m-d H:i');

    Livewire::test(CreateContent::class)
        ->assertOk()
        ->fillForm([
            'title' => 'Neue Seite',
            'path' => '/neue-seite',
            'publish_from' => $publishFrom,
        ])
        ->call('createAsDraft')
        ->assertHasNoFormErrors()
        ->assertNotified('Als Entwurf angelegt');

    $record = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/neue-seite')->firstOrFail();

    expect($record->title)->toBe('Neue Seite')
        ->and($record->publish_from)->toBeNull()
        ->and($record->status()->value)->toBe('draft')
        ->and($record->hasDraft())->toBeTrue()
        ->and($record->draftData()['publish_from'])->toBe($publishFrom);
});

it('keeps the classic create untouched (no stash, window applied)', function () {
    $publishFrom = now()->subHour()->format('Y-m-d H:i:s');

    Livewire::test(CreateContent::class)
        ->assertOk()
        ->fillForm([
            'title' => 'Sofort live',
            'path' => '/sofort-live',
            'publish_from' => $publishFrom,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/sofort-live')->firstOrFail();

    expect($record->hasDraft())->toBeFalse()
        ->and($record->status()->value)->toBe('published');
});

it('creates a fragment as draft: no active blocks until the draft is applied', function () {
    $blocks = [['type' => 'text', 'data' => ['active' => true, 'content' => '<p>Entwurfs-CTA</p>', 'heading' => 'h2']]];

    Livewire::test(CreateFragment::class)
        ->assertOk()
        ->fillForm([
            'title' => 'Neues Fragment',
            'slug' => 'neues-fragment',
            'blocks' => $blocks,
        ])
        ->call('createAsDraft')
        ->assertHasNoFormErrors()
        ->assertNotified('Als Entwurf angelegt');

    $fragment = Fragment::where('tenant_id', $this->tenant->getKey())->where('slug', 'neues-fragment')->firstOrFail();

    // Applied row renders nowhere; the blocks wait in the stash.
    expect($fragment->hasContent())->toBeFalse()
        ->and($fragment->hasDraft())->toBeTrue()
        ->and(Fragment::resolveFragment($this->tenant, 'neues-fragment'))->toBeNull()
        ->and($fragment->draftData()['blocks'][0]['data']['content'])->toContain('Entwurfs-CTA');

    // Applying the draft on the edit page publishes the blocks.
    Livewire::test(EditFragment::class, ['record' => $fragment->getKey()])
        ->assertOk()
        ->assertFormSet(['title' => 'Neues Fragment'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $fragment->fresh();

    expect($fresh->hasDraft())->toBeFalse()
        ->and($fresh->hasContent())->toBeTrue();
});

it('exposes the create/draft action pair in footer and header', function () {
    $component = Livewire::test(CreateContent::class)
        ->assertOk()
        ->assertActionExists('createDraftHeader')
        ->assertActionHasLabel('createDraftHeader', 'Als Entwurf anlegen')
        ->assertActionExists('createHeader')
        ->assertSee('Als Entwurf anlegen');

    $formActions = (new ReflectionMethod($component->instance(), 'getFormActions'))
        ->invoke($component->instance());

    expect(collect($formActions)->map(fn ($action) => $action->getName())->all())
        ->toBe(['create', 'createDraft', 'createAnother', 'cancel'])
        ->and($formActions[1]->getLabel())->toBe('Als Entwurf anlegen');
});
