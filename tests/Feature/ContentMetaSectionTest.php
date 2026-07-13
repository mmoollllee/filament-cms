<?php

/*
 * The Meta (SEO) section only applies to content types with their own URL:
 * a non-routable type (fixture, embedded record) never renders as a page, so
 * SEO overrides would be dead settings. The sidebar hides the section for
 * those types — statically on dedicated resources, reactively on the
 * catch-all (which follows the record's / the selected content type).
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $this->tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
});

it('shows the Meta section for a routable type', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->assertSee('SEO-Titel');
});

it('hides the Meta section when editing a non-routable type', function () {
    $note = Content::create([
        'tenant_id' => $this->tenant->getKey(),
        'content_type' => 'marketing.note',
        'title' => 'Interne Notiz',
    ]);

    Livewire::test(EditContent::class, ['record' => $note->getKey()])
        ->assertOk()
        ->assertDontSee('SEO-Titel');
});

it('hides the Meta section when creating a non-routable type via the ?type= deep-link', function () {
    Livewire::withQueryParams(['type' => 'marketing.note'])
        ->test(CreateContent::class)
        ->assertOk()
        ->assertFormSet(['content_type' => 'marketing.note'])
        ->assertDontSee('SEO-Titel');
});

it('keeps stored meta values untouched when saving a non-routable record', function () {
    // Hidden fields are not dehydrated: saving must leave meta as-is (e.g. values
    // left over from when the record's type was still routable), not blank it.
    $note = Content::create([
        'tenant_id' => $this->tenant->getKey(),
        'content_type' => 'marketing.note',
        'title' => 'Interne Notiz',
        'meta' => ['seo_title' => 'Alter Titel', 'custom_key' => 'bleibt'],
    ]);

    // The catch-all edit form binds the title input to `path` and requires it even
    // for non-routable types (pre-existing quirk) — fill it to get past validation;
    // GeneratesPathAndSlug discards it again for non-routable types on save.
    Livewire::test(EditContent::class, ['record' => $note->getKey()])
        ->fillForm(['path' => '/interne-notiz'])
        ->call('save')
        ->assertHasNoFormErrors();

    $note->refresh();

    expect($note->meta)->toMatchArray([
        'seo_title' => 'Alter Titel',
        'custom_key' => 'bleibt',
    ])->and($note->path)->toBeNull();
});
