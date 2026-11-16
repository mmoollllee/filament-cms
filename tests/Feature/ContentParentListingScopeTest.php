<?php

/*
 * A template that lists its OWN children (`children` in Cms::templateEmbeds()) is
 * the only place a record without a page of its own shows: it fetches them with
 * visibleChildren(), so a download filed under any other section saves, reads as
 * published, and never appears. The parent select therefore offers only the
 * sections whose template lists the type — and steps aside when exactly one does.
 *
 * A record with a page of its own shows there wherever it lives, so its parent
 * stays free. A plain `contentTypes` declaration makes no claim either: a home
 * page showing the three newest articles, wherever they live, is not where
 * articles belong.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    $this->makeContent = fn (string $type, string $title, ?string $template = null, ?Content $parent = null): Content => Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => $type,
        'title' => $title,
        'template' => $template,
        'parent_id' => $parent?->id,
    ]);

    $this->parentOptions = fn (string $type, ?Content $record = null): array => (new ReflectionMethod(CatchAllContentResource::class, 'getParentOptions'))
        ->invoke(null, $this->tenant, $type, $record);

    $this->downloads = ($this->makeContent)('default.section', 'Downloads', 'content.section-downloads');
    $this->leistungen = ($this->makeContent)('default.section', 'Leistungen');
});

it('offers only the sections whose template lists the type among their children', function () {
    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    expect(($this->parentOptions)('marketing.download'))->toBe([$this->downloads->id => 'Downloads']);
});

it('narrows nothing where no section is singled out', function () {
    // A list from anywhere: says nothing about where the records live.
    Cms::templateEmbeds('content.section-downloads', contentTypes: ['marketing.download']);

    expect(($this->parentOptions)('marketing.download'))->toHaveKeys([$this->downloads->id, $this->leistungen->id]);

    // A listing template no section uses yet: better every choice than a form
    // that cannot be saved.
    Cms::templateEmbeds('content.archiv', children: ['marketing.download']);

    expect(($this->parentOptions)('marketing.download'))->toHaveKeys([$this->downloads->id, $this->leistungen->id]);

    // Every page lists them: none is their one place.
    Cms::templateEmbeds(['*', 'content.section-downloads'], children: ['marketing.download']);

    expect(($this->parentOptions)('marketing.download'))->toHaveKeys([$this->downloads->id, $this->leistungen->id]);
});

it('keeps the parent a record already has, so it stays editable until it is moved', function () {
    $filedElsewhere = ($this->makeContent)('marketing.download', 'Zertifikat', parent: $this->leistungen);

    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    expect(($this->parentOptions)('marketing.download', $filedElsewhere))
        ->toBe([$this->downloads->id => 'Downloads', $this->leistungen->id => 'Leistungen']);
});

it('preselects the one section that lists the type, keeps the select out of the way and saves it', function () {
    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    Livewire::withQueryParams(['type' => 'marketing.download'])
        ->test(CreateContent::class)
        ->assertFormSet(['parent_id' => $this->downloads->id])
        ->assertFormFieldHidden('parent_id')
        ->fillForm(['title' => 'Zertifikat', 'slug' => 'zertifikat', 'path' => '/zertifikat'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Content::query()->where('title', 'Zertifikat')->sole()->parent_id)->toBe($this->downloads->id);
});

it('does not preselect a section the list was scoped to when its template does not list the type', function () {
    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    Livewire::withQueryParams(['type' => 'marketing.download', 'parent' => $this->leistungen->id])
        ->test(CreateContent::class)
        ->assertFormSet(['parent_id' => $this->downloads->id]);
});

it('says so when no section lists the type yet, and saves the record all the same', function () {
    // A listing template no section uses yet.
    Cms::templateEmbeds('content.archiv', children: ['marketing.download']);

    Livewire::withQueryParams(['type' => 'marketing.download'])
        ->test(CreateContent::class)
        ->assertFormFieldVisible('parent_id')
        ->assertSee('Noch zeigt keine Seite solche Einträge an.')
        ->fillForm(['title' => 'Zertifikat', 'slug' => 'zertifikat', 'path' => '/zertifikat'])
        ->call('create')
        ->assertHasNoFormErrors();

    // Once a section's template lists the type, there is a place to choose.
    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    Livewire::withQueryParams(['type' => 'marketing.download'])
        ->test(CreateContent::class)
        ->assertDontSee('Noch zeigt keine Seite solche Einträge an.');
});

it('asks for one of them when several sections list the type', function () {
    ($this->makeContent)('default.section', 'Formulare', 'content.section-downloads');

    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    Livewire::withQueryParams(['type' => 'marketing.download'])
        ->test(CreateContent::class)
        ->assertFormFieldVisible('parent_id')
        ->assertFormSet(['parent_id' => null])
        ->fillForm(['title' => 'Zertifikat', 'slug' => 'zertifikat', 'path' => '/zertifikat'])
        ->call('create')
        ->assertHasFormErrors(['parent_id']);
});

it('leaves a record filed elsewhere editable, with the select in view to move it', function () {
    $filedElsewhere = ($this->makeContent)('marketing.download', 'Zertifikat', parent: $this->leistungen);

    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    Livewire::test(EditContent::class, ['record' => $filedElsewhere->getRouteKey()])
        ->assertFormFieldVisible('parent_id')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($filedElsewhere->fresh()->parent_id)->toBe($this->leistungen->id);
});

it('keeps a record in its listing section when saved with the select out of the way', function () {
    $child = ($this->makeContent)('marketing.download', 'Zertifikat', parent: $this->downloads);

    Cms::templateEmbeds('content.section-downloads', children: ['marketing.download']);

    Livewire::test(EditContent::class, ['record' => $child->getRouteKey()])
        ->assertFormFieldHidden('parent_id')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($child->fresh()->parent_id)->toBe($this->downloads->id);
});

it('leaves the parent free for a type with pages of its own, the listing page included', function () {
    // An overview page listing its sub-pages: they show at their own URL
    // wherever they live, and the overview itself stays a root page.
    $overview = ($this->makeContent)('default.page', 'Ratgeber', 'content.overview');
    $root = ($this->makeContent)('default.page', 'Über uns');

    Cms::templateEmbeds('content.overview', children: ['default.page']);

    expect(($this->parentOptions)('default.page', $root))->toHaveKey($overview->id)
        ->and(count(($this->parentOptions)('default.page', $root)))->toBeGreaterThan(1);

    foreach ([$overview, $root] as $page) {
        Livewire::test(EditContent::class, ['record' => $page->getRouteKey()])
            ->assertFormFieldVisible('parent_id')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($page->fresh()->parent_id)->toBeNull();
    }

    Livewire::test(CreateContent::class)
        ->assertFormSet(['parent_id' => null])
        ->fillForm(['content_type' => 'default.page', 'title' => 'Wurzelseite', 'path' => '/wurzelseite'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Content::query()->where('title', 'Wurzelseite')->sole()->parent_id)->toBeNull();
});

it('lists the parents by their title as the model reads it', function () {
    $model = Cms::contentModel();
    $labelled = new class extends Content
    {
        protected $table = 'contents';

        public function getTitleAttribute(?string $value): string
        {
            return '» '.$value;
        }
    };

    Cms::useContentModel($labelled::class);

    try {
        expect(($this->parentOptions)('marketing.download'))->toContain('» Downloads', '» Leistungen');
    } finally {
        Cms::useContentModel($model);
    }
});
