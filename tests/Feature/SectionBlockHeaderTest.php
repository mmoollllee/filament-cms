<?php

/*
 * The section block's header on demand. Most sections never carry an eyebrow or
 * an intro text, so the form shows those two fields only once an editor asks for
 * them ("Kopfbereich") or they hold content — instead of an empty rich editor, a
 * header-layout select and an eyebrow input in every section. The header layout
 * moved into the block options, next to the other layout settings.
 *
 * Hidden fields are not saved, so the tests pin both halves: nothing filled may
 * ever be hidden, and the panel-only switch must never reach the database.
 */

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\RichText;
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

/**
 * A page made of root sections, one per data array.
 *
 * @param  array<int, array<string, mixed>>  $sections
 */
function pageWithSections(Tenant $tenant, array $sections): Content
{
    return Content::factory()->for($tenant)->create([
        'content_type' => 'default.page',
        'blocks' => array_map(
            fn (array $data): array => ['type' => 'section', 'data' => ['active' => true, 'blocks' => [], ...$data]],
            $sections,
        ),
    ]);
}

it('shows a section without eyebrow or intro as a single "Kopfbereich hinzufügen" link', function () {
    $page = pageWithSections($this->tenant, [['title' => 'Ohne Kopf']]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()])->assertOk();
    $uuid = array_key_first($component->get('data.blocks'));

    $component
        ->assertSeeHtml("mountAction('addSectionHeader'")
        ->assertSee('Kopfbereich hinzufügen')
        ->assertDontSeeHtml("data.blocks.{$uuid}.data.eyebrow")
        // The header layout is no longer an inline field of the section.
        ->assertDontSee('Header-Layout');
});

it('shows eyebrow and intro right away once a section has either', function () {
    $page = pageWithSections($this->tenant, [
        ['title' => 'Mit Intro', 'content' => '<p>Einleitung</p>'],
        ['title' => 'Mit Eyebrow', 'eyebrow' => 'Rubrik'],
    ]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()])->assertOk();
    [$withIntro, $withEyebrow] = array_keys($component->get('data.blocks'));

    $component
        ->assertDontSeeHtml("mountAction('addSectionHeader'")
        ->assertSeeHtml("data.blocks.{$withIntro}.data.eyebrow")
        ->assertSeeHtml("data.blocks.{$withEyebrow}.data.eyebrow");
});

it('never hides an intro that has no text but still holds content', function () {
    // An image-only intro has not a single character of text. Hiding it would drop
    // it on the next save — so it has to count as content.
    $page = pageWithSections($this->tenant, [['content' => '<p><img src="/storage/banner.jpg" alt=""></p>']]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()]);
    $uuid = array_key_first($component->get('data.blocks'));

    $component
        ->assertSeeHtml("data.blocks.{$uuid}.data.eyebrow")
        ->call('save')
        ->assertHasNoErrors();

    expect($page->refresh()->blocks[0]['data']['content'])->toContain('banner.jpg');
});

it('keeps the header open while its intro is cleared to be rewritten', function () {
    $page = pageWithSections($this->tenant, [['title' => 'Mit Intro', 'content' => '<p>Einleitung</p>']]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()]);
    $uuid = array_key_first($component->get('data.blocks'));

    // What the editor sends on blur once its text is deleted.
    $component
        ->set("data.blocks.{$uuid}.data._content_editor", '<p></p>')
        ->assertSet("data.blocks.{$uuid}.data.content", '<p></p>')
        ->assertSeeHtml("data.blocks.{$uuid}.data.eyebrow")
        ->assertDontSeeHtml("mountAction('addSectionHeader'");
});

it('still saves header fields while they are folded away', function () {
    // Blank by the heuristic, so the fields stay hidden — yet what they hold is
    // saved as it is instead of silently dropped.
    $page = pageWithSections($this->tenant, [['title' => 'Leerer Kopf', 'content' => '<p><br></p>']]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()]);
    $uuid = array_key_first($component->get('data.blocks'));

    $component
        ->assertDontSeeHtml("data.blocks.{$uuid}.data.eyebrow")
        ->call('save')
        ->assertHasNoErrors();

    expect($page->refresh()->blocks[0]['data']['content'] ?? null)->toBe('<p><br></p>');
});

it('reveals eyebrow and intro through the "Kopfbereich" link', function () {
    $page = pageWithSections($this->tenant, [['title' => 'Ohne Kopf']]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()]);
    $uuid = array_key_first($component->get('data.blocks'));

    $component
        ->assertDontSeeHtml("data.blocks.{$uuid}.data.eyebrow")
        ->callAction(TestAction::make('addSectionHeader')->schemaComponent("blocks.{$uuid}.data"))
        ->assertHasNoActionErrors()
        ->assertSeeHtml("data.blocks.{$uuid}.data.eyebrow")
        ->assertDontSeeHtml("mountAction('addSectionHeader'");
});

it('keeps a filled header on save and never saves the panel-only switch', function () {
    $page = pageWithSections($this->tenant, [
        ['title' => 'Mit Kopf', 'eyebrow' => 'Rubrik', 'content' => '<p>Einleitung</p>'],
        ['title' => 'Ohne Kopf'],
    ]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()]);
    [, $withoutHeader] = array_keys($component->get('data.blocks'));

    $component
        ->set("data.blocks.{$withoutHeader}.data.show_header", true)
        ->call('save')
        ->assertHasNoErrors();

    $blocks = $page->refresh()->blocks;

    expect($blocks[0]['data']['eyebrow'])->toBe('Rubrik')
        ->and($blocks[0]['data']['content'])->toContain('Einleitung')
        ->and($blocks[0]['data'])->not->toHaveKey('show_header')
        ->and($blocks[1]['data'])->not->toHaveKey('show_header');
});

it('offers and saves the header layout in the block options of a section', function () {
    $headerPreset = LayoutPreset::query()->create([
        'scope' => ['section-header'],
        'type' => 'Breite',
        'title' => 'Schmaler Kopf',
        'classes' => 'max-w-2xl',
    ]);

    $page = pageWithSections($this->tenant, [['title' => 'Sektion']]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()]);
    $uuid = array_key_first($component->get('data.blocks'));
    $options = TestAction::make('blockOptions')->schemaComponent('blocks')->arguments(['item' => $uuid]);

    $component
        ->mountAction($options)
        ->assertActionMounted($options)
        ->assertFormFieldExists('header_preset_ids')
        ->assertFormFieldExists('background_image')
        ->fillForm(['header_preset_ids' => [$headerPreset->getKey()]])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->call('save')
        ->assertHasNoErrors();

    expect(array_map('intval', $page->refresh()->blocks[0]['data']['header_preset_ids']))
        ->toBe([$headerPreset->getKey()]);
});

it('keeps the header layout out of the block options of other blocks', function () {
    $page = Content::factory()->for($this->tenant)->create([
        'content_type' => 'default.page',
        'blocks' => [['type' => 'section', 'data' => ['active' => true, 'blocks' => [
            ['type' => 'text', 'data' => ['active' => true, 'content' => '<p>Kind</p>']],
        ]]]],
    ]);

    $component = Livewire::test(EditContent::class, ['record' => $page->getKey()]);
    $section = array_key_first($component->get('data.blocks'));
    $child = array_key_first($component->get("data.blocks.{$section}.data.blocks"));

    $component
        ->mountAction(TestAction::make('blockOptions')->schemaComponent("blocks.{$section}.data.blocks")->arguments(['item' => $child]))
        ->assertFormFieldDoesNotExist('header_preset_ids')
        ->assertFormFieldDoesNotExist('background_image')
        ->assertFormFieldExists('anchor_id');
});

it('names the header-layout field apart from the layout field it sits next to', function () {
    // LayoutPreset::selectField() calls its field `layout_preset_ids`; the options
    // dialog collects its keys by field name, so an unrenamed header select would
    // silently overwrite the section's own layout.
    $method = new ReflectionMethod(BaseBuilderBlock::class, 'sectionHeaderPresetField');

    /** @var Select $field */
    $field = $method->invoke(null, $this->tenant);

    expect($field->getName())->toBe('header_preset_ids')
        ->and($field->getStatePath(isAbsolute: false))->toBe('header_preset_ids');
});

it('renders the Editor/HTML switch as the compact corner control', function () {
    $page = pageWithSections($this->tenant, [['title' => 'Mit Intro', 'content' => '<p>Einleitung</p>']]);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertSeeHtml('fi-cms-editor-tabs')
        // The tab labels double as tooltips (builder.css). A title attribute would
        // land on the tab panel as well — a tooltip over the whole editor.
        ->assertSeeText('HTML-Quelltext')
        ->assertDontSeeHtml('title="Editor"')
        ->assertDontSeeHtml('title="HTML-Quelltext"');
});

it('treats only markup without content as blank', function (mixed $content, bool $blank) {
    expect(RichText::isBlank($content))->toBe($blank);
})->with([
    'null' => [null, true],
    'empty string' => ['', true],
    'empty paragraph' => ['<p></p>', true],
    'paragraphs of whitespace, nbsp and breaks' => ["<p> </p><p>&nbsp;</p><p><br></p>\n", true],
    'empty TipTap document' => [['type' => 'doc', 'content' => [['type' => 'paragraph']]], true],
    'text' => ['<p>Hallo</p>', false],
    'image only' => ['<p><img src="/a.jpg" alt=""></p>', false],
    // A paragraph opening with a long run of non-breaking spaces (pasted from Word):
    // the check must neither give up on it nor call it blank.
    'text after a long run of nbsp' => ['<p>'.str_repeat("\u{00A0}", 5000).'Text</p>', false],
    'a long run of nbsp only' => ['<p>'.str_repeat("\u{00A0}", 5000).'</p>', true],
    'custom div only' => ['<div class="note"></div>', false],
    'TipTap text' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hallo']]]]], false],
]);
