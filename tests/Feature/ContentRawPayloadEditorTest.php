<?php

/*
 * The catch-all form keeps the raw payload editor in the tree (visibility is
 * toggled per selected type). Filament removes a HIDDEN component's state path
 * from the dehydrated state entirely — bound to `payload`, the hidden editor
 * therefore erased the whole branch on save, including the structured
 * page-header fields (payload.hero.*) the form itself manages. The editor now
 * works on a `raw_payload` copy that the pages fold back explicitly.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

function heroPage(Tenant $tenant): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Hero-Fixture',
        'path' => '/hero-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'payload' => ['hero' => [
            'size' => 'gross',
            'title' => 'Hero-Titel',
            'cta_label' => 'Alter Button-Text',
            'cta_url' => '/ziel',
        ]],
    ]);
}

it('saves edits to the managed page-header fields despite the hidden raw payload editor', function () {
    $page = heroPage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertFormSet(['payload.hero.cta_label' => 'Alter Button-Text'])
        ->fillForm(['payload.hero.cta_label' => 'Neuer Button-Text'])
        ->call('save')
        ->assertHasNoFormErrors();

    $payload = $page->fresh()->payload;

    expect(data_get($payload, 'hero.cta_label'))->toBe('Neuer Button-Text')
        // Untouched hero keys survive alongside the edit.
        ->and(data_get($payload, 'hero.cta_url'))->toBe('/ziel')
        ->and(data_get($payload, 'hero.size'))->toBe('gross');
});

it('stashes page-header edits into the draft as well', function () {
    $page = heroPage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->fillForm(['payload.hero.cta_label' => 'Entwurfs-Button'])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    $fresh = $page->fresh();

    expect(data_get($fresh->payload, 'hero.cta_label'))->toBe('Alter Button-Text')
        ->and(data_get($fresh->draftData(), 'payload.hero.cta_label'))->toBe('Entwurfs-Button');
});

it('persists deletions made in the VISIBLE raw payload editor', function () {
    // marketing.note opts into the raw payload editor (showsPayloadEditor).
    $note = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.note',
        'title' => 'Editor-Fixture',
        'slug' => 'editor-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'payload' => ['keep_me' => 'ja', 'legacy_flag' => 'weg-damit'],
    ]);

    $component = Livewire::test(EditContent::class, ['record' => $note->getKey()])->assertOk();

    // The editor mirrors the STORED payload (no field-hydration artifacts).
    // KeyValue keeps its state as [{key, value}] rows — normalize either shape.
    $mirror = $component->get('data.raw_payload');
    $mirror = array_is_list((array) $mirror)
        ? collect($mirror)->pluck('value', 'key')->all()
        : (array) $mirror;

    expect($mirror)->toMatchArray(['keep_me' => 'ja', 'legacy_flag' => 'weg-damit']);

    // … and a row deleted in it must STAY deleted (the record-preservation
    // merge is skipped while the visible editor holds the whole truth).
    // The path value only satisfies the catch-all's required rule — the
    // saving hook nulls it again for this non-routable type (pre-existing
    // catch-all quirk, unrelated to the editor).
    $component
        ->fillForm([
            'raw_payload' => ['keep_me' => 'ja'],
            'path' => '/editor-fixture-tmp',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $payload = $note->fresh()->payload;

    expect(data_get($payload, 'keep_me'))->toBe('ja')
        // Other form sections may contribute their own payload keys (hero
        // fields render for every catch-all type) — only the deletion matters.
        ->and($payload)->not->toHaveKey('legacy_flag');
});

it('folds the raw_payload copy back into payload, structured fields winning', function () {
    $merged = TenantScopedContentResource::mergeRawPayload([
        'title' => 'Seite',
        'payload' => ['hero' => ['title' => 'Strukturiert'], 'shared' => 'aus-feld'],
        'raw_payload' => ['shared' => 'aus-editor', 'custom_flag' => 'ja'],
    ]);

    expect($merged)->not->toHaveKey('raw_payload')
        ->and($merged['payload'])->toBe([
            'hero' => ['title' => 'Strukturiert'],
            'shared' => 'aus-feld',
            'custom_flag' => 'ja',
        ]);

    // Editor hidden → key absent → payload passes through untouched.
    $untouched = TenantScopedContentResource::mergeRawPayload([
        'payload' => ['hero' => ['title' => 'Bleibt']],
    ]);

    expect($untouched['payload'])->toBe(['hero' => ['title' => 'Bleibt']]);
});
