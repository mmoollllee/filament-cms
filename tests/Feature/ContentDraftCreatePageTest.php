<?php

/*
 * The secondary "hold it back" create action (CreatesDrafts):
 *
 * - Content ("Unveröffentlicht anlegen"): the row is created with an EMPTY
 *   publishing window and the full entered state applied — no draft stash
 *   (an unpublished row protects nothing; a stash would only reopen the edit
 *   page in a pointless "Entwurf geladen" state).
 * - Fragments ("Als Entwurf anlegen"): no publishing window exists, so the
 *   applied row is neutralized (no active blocks → renders nowhere) and the
 *   FULL form state is stashed — the edit page continues in the draft workflow.
 * - The classic "Erstellen" stays untouched (no stash, window applied).
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

it('creates content unpublished: full state applied, empty window, NO stash', function () {
    // The pickers run with seconds(false) — form state dehydrates minute-precise.
    $publishFrom = now()->subHour()->format('Y-m-d H:i');

    Livewire::test(CreateContent::class)
        ->assertOk()
        ->fillForm([
            'title' => 'Neue Seite',
            'path' => '/neue-seite',
            'is_published' => true,
            'publish_from' => $publishFrom,
        ])
        ->call('createAsDraft')
        ->assertHasNoFormErrors()
        ->assertNotified('Unveröffentlicht angelegt');

    $record = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/neue-seite')->firstOrFail();

    expect($record->title)->toBe('Neue Seite')
        ->and($record->publish_from)->toBeNull()
        ->and($record->status()->value)->toBe('draft')
        // No stash: the applied (unpublished) row IS the working state.
        ->and($record->hasDraft())->toBeFalse();
});

it('keeps the classic create untouched (no stash, window applied)', function () {
    $publishFrom = now()->subHour()->format('Y-m-d H:i:s');

    Livewire::test(CreateContent::class)
        ->assertOk()
        ->fillForm([
            'title' => 'Sofort live',
            'path' => '/sofort-live',
            'is_published' => true,
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

it('exposes the create action pair with per-model labels in footer and header', function () {
    $component = Livewire::test(CreateContent::class)
        ->assertOk()
        ->assertActionExists('createDraftHeader')
        ->assertActionHasLabel('createDraftHeader', 'Unveröffentlicht anlegen')
        ->assertActionExists('createHeader')
        ->assertSee('Unveröffentlicht anlegen');

    $formActions = (new ReflectionMethod($component->instance(), 'getFormActions'))
        ->invoke($component->instance());

    expect(collect($formActions)->map(fn ($action) => $action->getName())->all())
        ->toBe(['create', 'createDraft', 'createAnother', 'cancel'])
        ->and($formActions[1]->getLabel())->toBe('Unveröffentlicht anlegen');

    // Fragments have no publishing window — they keep the stash-based label.
    Livewire::test(CreateFragment::class)
        ->assertOk()
        ->assertActionHasLabel('createDraftHeader', 'Als Entwurf anlegen');
});
