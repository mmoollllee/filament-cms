<?php

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Content\Blocks\note\NoteBlock;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

it('ships the note block in the default set', function () {
    expect(Cms::defaultBlocks())->toContain(NoteBlock::class);
});

it('treats a block type without a frontend view as editor-only', function () {
    expect(BuilderBlockRegistry::rendersInFrontend('note'))->toBeFalse()
        ->and(BuilderBlockRegistry::rendersInFrontend('text'))->toBeTrue()
        ->and(BuilderBlockRegistry::rendersInFrontend('section'))->toBeTrue()
        ->and(BuilderBlockRegistry::rendersInFrontend(null))->toBeFalse();
});

it('never renders a note block on the site', function () {
    $tenant = Tenant::factory()->create();

    $content = Content::factory()->for($tenant)->create([
        'content_type' => 'default.page',
        'blocks' => [
            ['type' => 'note', 'data' => ['title' => 'Interne Notiz', 'content' => '<p>Nur fürs Panel</p>']],
            ['type' => 'text', 'data' => ['title' => 'Sichtbar', 'content' => '<p>Auf der Seite</p>']],
        ],
    ]);

    $html = view('cms::components.site.content-blocks', [
        'content' => $content,
        'tenant' => $tenant,
    ])->render();

    expect($html)->not->toContain('Nur fürs Panel')
        ->and($html)->not->toContain('Interne Notiz')
        ->and($html)->toContain('Auf der Seite');
});

it('never renders a note block nested inside a section', function () {
    $tenant = Tenant::factory()->create();

    $content = Content::factory()->for($tenant)->create([
        'content_type' => 'default.page',
        'blocks' => [[
            'type' => 'section',
            'data' => [
                'blocks' => [
                    ['type' => 'note', 'data' => ['title' => 'Interne Notiz', 'content' => '<p>Nur fürs Panel</p>']],
                    ['type' => 'text', 'data' => ['content' => '<p>Auf der Seite</p>']],
                ],
            ],
        ]],
    ]);

    $html = view('cms::components.site.content-blocks', [
        'content' => $content,
        'tenant' => $tenant,
    ])->render();

    expect($html)->not->toContain('Nur fürs Panel')
        ->and($html)->toContain('Auf der Seite');
});

it('keeps note blocks out of the jump navigation', function () {
    $tenant = Tenant::factory()->create();

    $content = Content::factory()->for($tenant)->create([
        'content_type' => 'default.page',
        'path' => '/leistungen',
        'blocks' => [
            ['type' => 'note', 'data' => ['title' => 'Interne Notiz']],
            ['type' => 'text', 'data' => ['title' => 'Erster Abschnitt']],
            ['type' => 'text', 'data' => ['title' => 'Zweiter Abschnitt']],
        ],
    ]);

    $context = app(NavigationContextBuilder::class)->build($tenant, $content);

    expect(array_column($context['localSections'], 'label'))
        ->toBe(['Erster Abschnitt', 'Zweiter Abschnitt'])
        ->and($context['blockAnchors'])->not->toHaveKey(0);
});

it('keeps links inside a note preview clickable', function () {
    // A note exists to point at the resource where the real records live, so a
    // dead button in it defeats the block. Three files have to agree on that:
    // the preview marks its subtree, builder.css lets the pointer through, and
    // the preview's click handler skips its preventDefault() for it. Editing one
    // half alone leaves a button that looks live and does nothing, which is why
    // all three are asserted together.
    $preview = view('blocks::note.preview', [
        'title' => 'Leistungen-Slider',
        'content' => '<p><a href="/panel/leistungen">Leistungen bearbeiten</a></p>',
    ])->render();

    expect($preview)->toContain('fi-cms-preview-interactive')
        ->toContain('href="/panel/leistungen"');

    expect(file_get_contents(__DIR__.'/../../resources/css/builder.css'))
        ->toContain('.fi-cms-preview-interactive a');

    expect(file_get_contents(__DIR__.'/../../resources/overrides/filament-forms/components/builder.blade.php'))
        ->toContain("closest('.fi-cms-preview-interactive a");
});
