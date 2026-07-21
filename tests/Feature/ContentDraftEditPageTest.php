<?php

/*
 * The draft-aware edit flow (ManagesDrafts) on the content + fragment edit pages:
 *
 * - "Entwurf speichern" (saveDraft) stashes the validated form state without
 *   applying it; reloading the form continues on the draft (incl. the virtual
 *   status derived from the DRAFT publishing window).
 * - "Änderungen anwenden" (save) applies the form state and clears the stash.
 * - "Entwurf verwerfen" drops the stash and refills the applied values.
 * - The delete action lives in the form footer (trash icon button), the
 *   draft/apply pair renders in footer AND header, plus the Vorschau link.
 */

use Illuminate\Support\Js;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Fragments\Pages\EditFragment;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

function draftEditFixture(Tenant $tenant): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Live-Titel',
        'path' => '/draft-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subWeek(),
    ]);
}

it('stashes a draft via saveDraft without applying it', function () {
    $record = draftEditFixture($this->tenant);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->fillForm(['title' => 'Entwurfs-Titel'])
        ->call('saveDraft')
        ->assertHasNoFormErrors()
        // The preview click handler gates the tab on this return value.
        ->assertReturned(true)
        ->assertNotified('Entwurf gespeichert');

    $fresh = $record->fresh();

    expect($fresh->title)->toBe('Live-Titel')
        ->and($fresh->hasDraft())->toBeTrue()
        ->and($fresh->draftData()['title'])->toBe('Entwurfs-Titel');
});

it('does not stash (nor confirm to the preview handler) when validation fails', function () {
    $record = draftEditFixture($this->tenant);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->fillForm(['title' => ''])
        ->call('saveDraft')
        ->assertHasFormErrors(['title'])
        // Validation aborts the method — no `true` reaches the preview handler.
        ->assertReturned(null);

    expect($record->fresh()->hasDraft())->toBeFalse();
});

it('loads a pending draft into the form, including the draft-derived status', function () {
    $record = draftEditFixture($this->tenant);

    // Draft reschedules the (currently published) page into the future.
    $record->stashDraft([
        'title' => 'Entwurfs-Titel',
        'publish_from' => now()->addWeek()->format('Y-m-d H:i:s'),
        'publish_until' => null,
    ]);

    expect($record->status()->value)->toBe('published');

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet([
            'title' => 'Entwurfs-Titel',
            'status' => 'scheduled',
        ])
        ->assertSee('noch nicht angewendet');
});

it('applies the draft state via save and clears the stash', function () {
    $record = draftEditFixture($this->tenant);
    $record->stashDraft(['title' => 'Entwurfs-Titel']);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $record->fresh();

    expect($fresh->title)->toBe('Entwurfs-Titel')
        ->and($fresh->hasDraft())->toBeFalse();
});

it('discards a pending draft and refills the applied values', function () {
    $record = draftEditFixture($this->tenant);
    $record->stashDraft(['title' => 'Entwurfs-Titel']);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['title' => 'Entwurfs-Titel'])
        ->callAction('discardDraft')
        ->assertNotified('Entwurf verworfen')
        ->assertFormSet(['title' => 'Live-Titel']);

    expect($record->fresh()->hasDraft())->toBeFalse();
});

it('exposes the draft/apply/preview/delete actions with the new labels', function () {
    $record = draftEditFixture($this->tenant);

    $component = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        // Header mirrors the pair and carries the preview link.
        ->assertActionExists('saveDraftHeader')
        ->assertActionHasLabel('saveDraftHeader', 'Entwurf speichern')
        ->assertActionExists('applyHeader')
        ->assertActionHasLabel('applyHeader', 'Änderungen anwenden')
        ->assertActionExists('preview')
        // No pending draft → nothing to discard.
        ->assertActionHidden('discardDraft')
        // Both save buttons render (footer + header duplicate the labels).
        ->assertSee('Änderungen anwenden')
        ->assertSee('Entwurf speichern');

    // Footer configuration: apply (relabeled save) + draft + cancel + trash icon.
    $formActions = (new ReflectionMethod($component->instance(), 'getFormActions'))
        ->invoke($component->instance());

    expect(collect($formActions)->map(fn ($action) => $action->getName())->all())
        ->toBe(['save', 'saveDraft', 'cancel', 'delete'])
        ->and($formActions[0]->getLabel())->toBe('Änderungen anwenden')
        ->and($formActions[1]->getLabel())->toBe('Entwurf speichern')
        ->and($formActions[3]->isIconButton())->toBeTrue();

    // Preview stashes first, then opens the tab: the click handler chains the
    // preview URL onto a successful $wire.saveDraft() roundtrip. The URL is
    // embedded Js::from()-encoded (escaped slashes), so compare that form.
    // The button locks only for the roundtrip itself ($el.disabled) — it must
    // never inherit the draft buttons' pristine-disabled state.
    $previewAction = $component->instance()->getAction('preview');
    $previewHandler = (string) $previewAction->getAlpineClickHandler();

    expect($previewHandler)->toContain('$wire.saveDraft()')
        ->toContain('$el.disabled = true')
        ->toContain('$el.disabled = false')
        ->toContain((string) Js::from(route('content.show', ['path' => 'draft-fixture', 'preview' => 1])))
        ->and($previewAction->getExtraAttributes()['disabled'] ?? null)->toBeNull();

    // Sibling actions appear/disappear (Entwurf verwerfen) — every header
    // action needs a stable morph identity, or buttons inherit each other's
    // DOM state after the list shifts.
    foreach (['preview', 'saveDraftHeader', 'applyHeader'] as $actionName) {
        expect($component->instance()->getAction($actionName)->getExtraAttributes()['wire:key'] ?? null)
            ->not->toBeNull();
    }
});

it('maintains the pristine hash that disables "Entwurf speichern" client-side', function () {
    $record = draftEditFixture($this->tenant);

    $expectedHashFor = fn ($instance): string => md5(
        (string) str(json_encode($instance->data, JSON_UNESCAPED_UNICODE))->replace('\\', ''),
    );

    $component = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk();

    // Mount fills the form → that state is the pristine baseline.
    expect($component->instance()->draftSavedDataHash)->toBe($expectedHashFor($component->instance()));

    // Editing diverges the data from the baseline (client would enable the
    // button); the baseline itself must NOT move along.
    $mountHash = $component->instance()->draftSavedDataHash;
    $component->fillForm(['title' => 'Entwurfs-Titel']);

    expect($component->instance()->draftSavedDataHash)->toBe($mountHash)
        ->and($expectedHashFor($component->instance()))->not->toBe($mountHash);

    // Stashing re-stamps the baseline to the current state → pristine again.
    $component->call('saveDraft')->assertHasNoFormErrors();

    expect($component->instance()->draftSavedDataHash)->toBe($expectedHashFor($component->instance()));

    // Both draft buttons carry the client-side disabled binding: rendered
    // disabled, released by the debounced pristine-hash Alpine effect.
    $footerDraftAttributes = (new ReflectionMethod($component->instance(), 'getFormActions'))
        ->invoke($component->instance())[1]->getExtraAttributes();
    $headerDraftAttributes = $component->instance()->getAction('saveDraftHeader')->getExtraAttributes();

    foreach ([$footerDraftAttributes, $headerDraftAttributes] as $attributes) {
        expect($attributes['disabled'] ?? null)->toBeTrue()
            ->and($attributes['x-bind:disabled'] ?? '')->toBe('draftPristine')
            ->and($attributes['x-effect'] ?? '')
            ->toContain('window.jsMd5')
            ->toContain('$wire.draftSavedDataHash');
    }
});

it('keeps a NEWER draft when an older tab applies its stale form state', function () {
    $record = draftEditFixture($this->tenant);
    $record->stashDraft(['title' => 'Alter Entwurf']);

    // This tab loads the current draft revision …
    $component = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['title' => 'Alter Entwurf']);

    // … then someone else stashes a NEWER draft.
    $this->travel(2)->seconds();
    $record->fresh()->stashDraft(['title' => 'Neuerer Entwurf']);

    $component->call('save')->assertHasNoFormErrors();

    $fresh = $record->fresh();

    // The stale apply went through, but the newer stash survived.
    expect($fresh->title)->toBe('Alter Entwurf')
        ->and($fresh->hasDraft())->toBeTrue()
        ->and($fresh->draftData()['title'])->toBe('Neuerer Entwurf');
});

it('hides the discard action until a draft is pending', function () {
    $record = draftEditFixture($this->tenant);
    $record->stashDraft(['title' => 'Entwurfs-Titel']);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertActionVisible('discardDraft');
});

it('stashes and applies fragment drafts on the fragment edit page', function () {
    $fragment = Fragment::create([
        'tenant_id' => $this->tenant->id,
        'title' => 'CTA',
        'slug' => 'cta-fixture',
        'blocks' => [],
    ]);

    Livewire::test(EditFragment::class, ['record' => $fragment->getKey()])
        ->assertOk()
        ->fillForm(['title' => 'CTA (Entwurf)'])
        ->call('saveDraft')
        ->assertHasNoFormErrors()
        ->assertNotified('Entwurf gespeichert');

    expect($fragment->fresh()->title)->toBe('CTA')
        ->and($fragment->fresh()->hasDraft())->toBeTrue();

    Livewire::test(EditFragment::class, ['record' => $fragment->getKey()])
        ->assertOk()
        ->assertFormSet(['title' => 'CTA (Entwurf)'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fragment->fresh()->title)->toBe('CTA (Entwurf)')
        ->and($fragment->fresh()->hasDraft())->toBeFalse();
});
