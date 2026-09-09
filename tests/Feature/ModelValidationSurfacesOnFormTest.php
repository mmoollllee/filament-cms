<?php

/*
 * A guard inside the MODEL — the path collision check runs for every writer, not only
 * for the form — throws under the model's own attribute name. Filament renders errors by
 * state path, so `path` reaches an error bag no component owns and is simply not drawn.
 *
 * With the panel's database transactions on, that is the worst of the three outcomes:
 * the editor presses Speichern, the write rolls back, and the screen says nothing. A
 * silent no-op is harder to act on than the 500 the guard replaced.
 *
 * The throw is staged here rather than provoked through a real collision on purpose: the
 * form rule now catches every collision it can see, so the only way left to reach the
 * model guard in the panel is a write that lands between validation and save. What needs
 * pinning is the wiring — that a model-raised error arrives on the field.
 */

use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Support\FormFieldValidation;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

/** Make the next content save fail the way the path guard does. */
function throwFromModelOnSave(): void
{
    Content::saving(function (): void {
        throw ValidationException::withMessages([
            'path' => 'Der Pfad „/kollision" ist bereits von „Andere Seite" belegt.',
        ]);
    });
}

it('shows a model-raised error on the form field when editing', function () {
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Seite',
        'path' => '/seite',
    ]);

    throwFromModelOnSave();

    Livewire::test(EditContent::class, ['record' => $page->getRouteKey()])
        ->call('save')
        ->assertHasFormErrors(['path']);
});

it('shows a model-raised error on the form field when creating', function () {
    throwFromModelOnSave();

    Livewire::test(CreateContent::class)
        ->fillForm([
            'content_type' => 'default.page',
            'title' => 'Neue Seite',
            'path' => '/neue-seite',
        ])
        ->call('create')
        ->assertHasFormErrors(['path']);
});

it('leaves an already-prefixed key alone', function () {
    $rekeyed = FormFieldValidation::rekey(
        ValidationException::withMessages(['data.path' => 'schon am Feld', 'path' => 'am Model']),
        'data',
    );

    expect(array_keys($rekeyed->errors()))->toBe(['data.path']);
});
