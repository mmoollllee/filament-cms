<?php

/*
 * Renders a content edit form (Livewire) so the OVERRIDDEN builder views
 * (resources/overrides/filament-forms/…) actually compile and the shared
 * BlockBuilder item actions (Block-Optionen, Block kopieren, clipboard paste,
 * cross-builder drag & drop — the latter three from
 * mmoollllee/filament-builder-extensions) are evaluated — the panel-side
 * regression net for the vendored views.
 */

use Filament\Facades\Filament;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\App\Support\Content\Blocks\hint\HintBlock;
use Workbench\Database\Seeders\DatabaseSeeder;

/** The rendered form, for what only its markup tells: which list an element sits in. */
function builderMarkup(string $html): DOMXPath
{
    $document = new DOMDocument;
    $usedInternalErrors = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($usedInternalErrors);

    return new DOMXPath($document);
}

/**
 * The paste calls the block pickers' "Aus Zwischenablage" entries hand the browser, each
 * with the key of the item it sits in: a section's for its blocks' pickers, null for the
 * page's own.
 *
 * @return list<array{in: string|null, name: string, kind: string, arguments: array<string, mixed>, context: array<string, mixed>}>
 */
function pickerPasteCalls(string $html): array
{
    $calls = [];

    foreach (builderMarkup($html)->query('//*[@*[name() = "x-on:click" and contains(., "FilamentBuilderExtensions?.paste(")]]') as $entry) {
        preg_match('/FilamentBuilderExtensions\?\.paste\(\$wire, JSON\.parse\(\'(.*?)\'\)\)/', $entry->getAttribute('x-on:click'), $match);

        $item = $entry->parentNode;

        while (($item instanceof DOMElement) && (! $item->hasAttribute('x-sortable-item'))) {
            $item = $item->parentNode;
        }

        $calls[] = [
            'in' => ($item instanceof DOMElement) ? $item->getAttribute('x-sortable-item') : null,
            ...json_decode(json_decode('"'.$match[1].'"'), true),
        ];
    }

    return $calls;
}

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $this->tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
});

it('renders the block builder on the content edit form with the cms item actions', function () {
    // The seeded home page: root sections with child blocks (text/media/listing).
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        // The docs site offers more than one routable type, so the type field renders
        // as the Seiten-Typ select (WHICH types it offers is pinned below).
        ->assertSee('Seiten-Typ')
        // Pages nest under pages: the parent select is offered.
        ->assertSee('Übergeordnete Seite')
        ->assertSee('Block-Optionen')                                    // shared options action (BlockBuilder)
        ->assertSee('Block kopieren')                                    // copy action (package CopyItemAction)
        ->assertSeeHtml('FilamentBuilderExtensions?.copy($wire')         // …copying in the browser
        ->assertSee('Aus Zwischenablage')                                // paste entry (block-picker override)
        ->assertSeeHtml('FilamentBuilderExtensions?.paste($wire')        // …running the package's PasteItemAction
        ->assertSeeHtml('data-transfer-group="section-blocks"')          // cross-builder drag & drop (package transferGroup())
        ->assertSeeHtml('data-clipboard-shortcuts=')                     // ⌘C / ⌘V on a selected block (package clipboardShortcuts())
        ->assertSee('Block hinzufügen')                                  // add action label
        // Regression: the override header comment must never leak into the page
        // (Blade comments don't nest — a literal token inside terminates early).
        ->assertDontSee('To re-vendor')
        ->assertDontSee('cms features carried');
});

it('offers the Sektion type only on sites that opted in', function () {
    // What the opt-in decides is the OPTION, not the field: every site with more than
    // one routable type renders the select, but only a site whose extension overrides
    // the default.section blueprint with offeredInTypeSelect lets editors pick it.
    // Asserted on the component's options rather than the rendered HTML — a page built
    // from section blocks says "Sektion" all over itself.
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->assertSchemaComponentExists(
            'content_type',
            'form',
            fn (Select $select): bool => ! array_key_exists('default.section', $select->getOptions()),
        );

    // Tenant B (site_key 'acme') is the onepager demo — it opted in.
    $tenantB = Tenant::where('site_key', 'acme')->firstOrFail();
    Filament::setTenant($tenantB);
    app(CurrentTenant::class)->set($tenantB);

    $section = Content::where('tenant_id', $tenantB->getKey())->where('path', '/leistungen')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $section->getKey()])
        ->assertOk()
        ->assertSee('Seiten-Typ')
        ->assertSchemaComponentExists(
            'content_type',
            'form',
            fn (Select $select): bool => array_key_exists('default.section', $select->getOptions()),
        );
});

it('pastes a copied block from the block picker, last or after the block above', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $editor = Livewire::test(EditContent::class, ['record' => $home->getKey()]);
    $sections = $editor->get('data.blocks');
    $documentation = array_key_last($sections);
    $blocks = $sections[$documentation]['data']['blocks'];
    $firstBlock = array_key_first($blocks);

    // What the picker entries hand the browser: the builder's paste action, between two
    // blocks invoked with the one above.
    $inDocumentation = collect(pickerPasteCalls($editor->html()))->where('in', $documentation);
    $last = $inDocumentation->first(fn (array $call): bool => $call['arguments'] === []);
    $after = $inDocumentation->first(fn (array $call): bool => ($call['arguments']['item'] ?? null) === $firstBlock);

    expect($last['name'])->toBe(BaseBuilderBlock::PASTE_ACTION)
        ->and($last['kind'])->toBe(BaseBuilderBlock::CLIPBOARD_KIND)
        ->and($after)->not->toBeNull();

    $copy = ['type' => 'text', 'data' => ['title' => 'Kopie', 'content' => '<p>Kopiert</p>', 'unknown' => 'weg']];

    $editor->call('mountAction', $after['name'], [...$after['arguments'], 'pasted' => $copy], $after['context'])
        ->call('mountAction', $last['name'], [...$last['arguments'], 'pasted' => $copy], $last['context']);

    $titles = array_map(fn (array $block): ?string => $block['data']['title'] ?? null, array_values($editor->get("data.blocks.{$documentation}.data.blocks")));

    expect($titles[1])->toBe('Kopie')
        ->and(end($titles))->toBe('Kopie')
        ->and(count($titles))->toBe(count($blocks) + 2)
        ->and(collect($editor->get("data.blocks.{$documentation}.data.blocks"))->pluck('data')->every(fn (array $data): bool => ! array_key_exists('unknown', $data)))->toBeTrue();
});

it('keeps the paste entries of every section on its own blocks after a paste, the bottom one without arguments', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $editor = Livewire::test(EditContent::class, ['record' => $home->getKey()]);
    $sections = $editor->get('data.blocks');
    $documentation = array_key_last($sections);
    $firstBlock = array_key_first($sections[$documentation]['data']['blocks']);
    $after = collect(pickerPasteCalls($editor->html()))
        ->where('in', $documentation)
        ->first(fn (array $call): bool => ($call['arguments']['item'] ?? null) === $firstBlock);

    // A block no builder offers: refused, nothing changes.
    $editor->call('mountAction', $after['name'], [...$after['arguments'], 'pasted' => ['type' => 'nope', 'data' => []]], $after['context']);

    $callsBySection = collect(pickerPasteCalls($editor->html()))->whereNotNull('in')->groupBy('in');

    // Every section's entries still paste into its own blocks (not into the section pasted
    // into last), and the bottom one is not asked after the item that paste was.
    expect($callsBySection->keys()->all())->toEqualCanonicalizing(array_keys(array_filter($sections, fn (array $item): bool => $item['type'] === 'section')));

    $callsBySection->each(fn ($calls, string $section) => expect($calls->every(fn (array $call): bool => str_ends_with($call['context']['schemaComponent'], "{$section}.data.blocks")))->toBeTrue()
        ->and($calls->contains(fn (array $call): bool => $call['arguments'] === []))->toBeTrue()
        ->and($calls->contains(fn (array $call): bool => array_key_exists('pasted', $call['arguments'])))->toBeFalse());
});

it('refuses a section\'s block among the sections and a section among a section\'s blocks, saying where each goes', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $editor = Livewire::test(EditContent::class, ['record' => $home->getKey()]);
    $blocks = $editor->get('data.blocks');
    $section = array_key_last(array_filter($blocks, fn (array $item): bool => $item['type'] === 'section'));
    $calls = collect(pickerPasteCalls($editor->html()));
    $belowSections = $calls->first(fn (array $call): bool => ($call['in'] === null) && ($call['arguments'] === []));
    $belowItsBlocks = $calls->first(fn (array $call): bool => ($call['in'] === $section) && ($call['arguments'] === []));

    // A text copied out of a section, pasted below the sections: the page offers sections alone.
    $editor->call('mountAction', $belowSections['name'], [...$belowSections['arguments'], 'pasted' => ['type' => 'text', 'data' => ['title' => 'Kopie']]], $belowSections['context']);

    expect($editor->get('data.blocks'))->toBe($blocks);

    Notification::assertNotified(Notification::make()->warning()->title('„Text“ kann hier nicht eingefügt werden')->body('Innerhalb von „Sektion“ einfügen.'));

    // A section, pasted below a section's blocks.
    $editor->call('mountAction', $belowItsBlocks['name'], [...$belowItsBlocks['arguments'], 'pasted' => ['type' => 'section', 'data' => ['title' => 'Kopie']]], $belowItsBlocks['context']);

    expect($editor->get('data.blocks'))->toBe($blocks);

    Notification::assertNotified(Notification::make()->warning()->title('„Sektion“ kann hier nicht eingefügt werden')->body('Außerhalb von „Sektion“ einfügen.'));
});

it('heads a picker\'s blocks with the groups their classes name, the ungrouped first and the clipboard entry below them', function () {
    // The workbench's hint block, grouped as a project with many blocks would group its own.
    app(BuilderBlockRegistry::class)->register(new class extends HintBlock
    {
        public function group(): ?string
        {
            return 'Hervorheben';
        }
    });

    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $markup = builderMarkup(Livewire::test(EditContent::class, ['record' => $home->getKey()])->html());
    $blocksIn = fn (DOMNode $node): array => array_map(
        // The picker hands mountAction() its arguments as Js::from() does: JSON.parse('{\u0022block\u0022: …}').
        fn (DOMElement $button): string => Str::match('/"block":"([^"]+)"/', str_replace('\u0022', '"', $button->getAttribute('wire:click'))),
        iterator_to_array($markup->query('.//*[@*[name() = "wire:click" and contains(., "block\u0022:")]]', $node)),
    );
    $pickers = $markup->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-fo-builder-block-picker-groups ")]');

    // Every section's pickers: its blocks between two and below them.
    expect($pickers->length)->toBeGreaterThan(1);

    foreach ($pickers as $picker) {
        [$ungrouped, $highlighted] = iterator_to_array($markup->query('./div', $picker));

        expect($ungrouped->getAttribute('aria-label'))->toBe('')
            ->and($blocksIn($ungrouped))->toContain('text', 'media')->not->toContain('hint')
            ->and($highlighted->getAttribute('aria-label'))->toBe('Hervorheben')
            ->and($markup->query('./*[contains(@class, "fi-fo-builder-block-picker-group-label")]', $highlighted)->item(0)?->textContent)->toBe('Hervorheben')
            ->and($blocksIn($highlighted))->toBe(['hint'])
            ->and($markup->query('following-sibling::*[@*[name() = "x-on:click" and contains(., "FilamentBuilderExtensions?.paste(")]]', $picker)->length)->toBe(1);
    }

    // The page's own picker offers sections alone: no heading.
    $sectionEntries = $markup->query('//*[@*[name() = "wire:click" and contains(., "block\u0022:\u0022section\u0022")]]');

    expect($sectionEntries->length)->toBeGreaterThan(0);

    foreach ($sectionEntries as $sectionEntry) {
        expect($markup->query('ancestor::*[contains(@class, "fi-fo-builder-block-picker-groups")]', $sectionEntry)->length)->toBe(0);
    }
});

it('loads a preview block\'s form only once it is edited, and by itself where it fails validation', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    // The first text block's section and eyebrow field.
    $firstText = function (array $blocks): array {
        foreach ($blocks as $section => $item) {
            foreach ($item['data']['blocks'] ?? [] as $key => $child) {
                if ($child['type'] === 'text') {
                    return [$section, "data.blocks.{$section}.data.blocks.{$key}.data.eyebrow"];
                }
            }
        }

        throw new RuntimeException('No text block on the home page.');
    };

    // Off by default: every form is rendered behind its preview.
    $eager = Livewire::test(EditContent::class, ['record' => $home->getKey()]);

    expect($eager->html())->toContain($firstText($eager->get('data.blocks'))[1])
        ->not->toContain('fi-sc-loading');

    Cms::enableLazyBlockForms();

    $lazy = Livewire::test(EditContent::class, ['record' => $home->getKey()]);
    [$section, $eyebrow] = $firstText($lazy->get('data.blocks'));

    // The text's form waits behind its preview; its section, which has none, renders its form
    // with the list of its blocks.
    expect($lazy->html())->not->toContain($eyebrow)
        ->toContain('fi-sc-loading')
        ->toContain("data-state-path=\"data.blocks.{$section}.data.blocks\"");

    // Saved without ever being opened, and failing: the form loads with its error.
    $lazy->set($eyebrow, str_repeat('x', 101))
        ->call('save')
        ->assertHasErrors([$eyebrow]);

    expect($lazy->html())->toContain($eyebrow);
});

it('has every preview block open its form on a validation error inside, not only the first that Filament expands', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $form = Livewire::test(EditContent::class, ['record' => $home->getKey()]);
    $markup = builderMarkup($form->html());

    $listens = fn (string $key): bool => $markup->query('//li[@x-sortable-item="'.$key.'"][@*[name() = "x-on:form-validation-error.window"]]')->length === 1;
    $previews = 0;

    foreach ($form->get('data.blocks') as $sectionKey => $section) {
        // A section shows its form anyway.
        expect($listens($sectionKey))->toBeFalse();

        foreach ($section['data']['blocks'] ?? [] as $key => $block) {
            if ($block['type'] === 'text') {
                expect($listens($key))->toBeTrue();
                $previews++;
            }
        }
    }

    expect($previews)->toBeGreaterThan(1);
});

it('keeps Livewire from morphing a builder whose Alpine component is still loading, on every panel page', function () {
    $guard = collect(FilamentAsset::getScripts(['mmoollllee/filament-cms']))
        ->first(fn (Js $script): bool => $script->getId() === 'filament-cms-async-morph-guard');

    // Not on request: the hook must be in place before the first update after the page loads.
    expect($guard)->not->toBeNull()
        ->and($guard->isLoadedOnRequest())->toBeFalse()
        ->and(file_get_contents($guard->getPath()))->toContain("hook('morph.updating'")
        ->toContain("el._x_async === 'await'");
});

it('adds a preview block from the picker with lazy block forms on', function () {
    Cms::enableLazyBlockForms();

    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $editor = Livewire::test(EditContent::class, ['record' => $home->getKey()]);
    $section = array_key_first(array_filter($editor->get('data.blocks'), fn (array $item): bool => $item['type'] === 'section'));
    $before = count($editor->get("data.blocks.{$section}.data.blocks"));

    // Filament's add modal takes the block's components as an array: the blocks keep theirs.
    $editor->call('mountAction', 'add', ['block' => 'text'], ['schemaComponent' => "form.blocks.{$section}.data.blocks"])
        ->call('callMountedAction');

    expect($editor->get("data.blocks.{$section}.data.blocks"))->toHaveCount($before + 1);
});

it('renders a preview block whose schema is a closure with lazy block forms on', function () {
    // A project block that builds its form from the Livewire component, as Filament allows.
    app(BuilderBlockRegistry::class)->register(new class extends HintBlock
    {
        public function make(?Mmoollllee\Cms\Contracts\Tenant $tenant): Block
        {
            $block = parent::make($tenant);
            $components = $block->getDefaultChildComponents();

            return $block->schema(fn ($livewire): array => $components);
        }
    });

    Cms::enableLazyBlockForms();

    $page = Content::where('tenant_id', $this->tenant->getKey())->get()
        ->first(fn (Content $content): bool => str_contains((string) json_encode($content->blocks), '"type":"hint"'));

    expect($page)->not->toBeNull();

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertSee('fi-sc-loading', escape: false);
});

it('moves a block into another section the way a drop there asks, from lists where the package\'s script finds them', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $editor = Livewire::test(EditContent::class, ['record' => $home->getKey()]);
    $markup = builderMarkup($editor->html());

    // The script takes a group's lists from the builder's element: its key, its state path
    // and, right inside it, the list itself — markup the override has to keep as it is.
    $keys = [];

    foreach ($markup->query('//*[@data-transfer-group="section-blocks"]') as $wrapper) {
        $keys[$wrapper->getAttribute('data-state-path')] = $wrapper->getAttribute('data-transfer-key');

        expect($markup->query('./ul[@x-sortable]//*[@x-sortable-item]', $wrapper)->length)
            ->toBe($markup->query('.//*[@x-sortable-item]', $wrapper)->length);
    }

    [$from, $to] = array_keys(array_filter($editor->get('data.blocks'), fn (array $item): bool => filled($item['data']['blocks'] ?? null)));
    $fromItems = array_keys($editor->get("data.blocks.{$from}.data.blocks"));
    $toItems = [$fromItems[0], ...array_keys($editor->get("data.blocks.{$to}.data.blocks"))];

    $editor->call('mountAction', 'transferItem', [
        'from' => $keys["data.blocks.{$from}.data.blocks"],
        'moved' => $fromItems[0],
        'fromItems' => array_slice($fromItems, 1),
        'toItems' => $toItems,
        'toIndex' => 0,
    ], ['schemaComponent' => $keys["data.blocks.{$to}.data.blocks"]]);

    expect(array_keys($editor->get("data.blocks.{$to}.data.blocks")))->toBe($toItems)
        ->and(array_keys($editor->get("data.blocks.{$from}.data.blocks")))->toBe(array_slice($fromItems, 1));
});

it('takes rich text edits deep inside a section\'s blocks, past Livewire\'s default nesting limit', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();
    $editor = Livewire::test(EditContent::class, ['record' => $home->getKey()]);

    // The deepest text of a child block's rich text, as the editor syncs it.
    $deepest = collect(Arr::dot($editor->get('data.blocks'), 'data.blocks.'))
        ->filter(fn (mixed $value, string $path): bool => is_string($value) && (preg_match('/^data\.blocks\.[^.]+\.data\.blocks\.[^.]+\.data\._content_editor\./', $path) === 1))
        ->keys()
        ->sortByDesc(fn (string $path): int => substr_count($path, '.'))
        ->first();

    expect(substr_count($deepest, '.') + 1)->toBeGreaterThan(10);

    $editor->set($deepest, 'Tief geändert');

    expect($editor->get($deepest))->toBe('Tief geändert');
});
