<?php

/*
 * The package's opt-in fragment block: embeds a fragment by slug (resolved through
 * the branding cascade on the site), picks it from a list in the panel, names it
 * in the row title and links from its preview to where the fragment is edited.
 *
 * Workbench: the `cta` fragment belongs to the marketing tenant, which is also the
 * branding tenant — acme inherits it.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Support\Content\Blocks\fragment\FragmentBlock;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->marketing = Tenant::where('site_key', 'marketing')->firstOrFail();
    $this->acme = Tenant::where('site_key', 'acme')->firstOrFail();
    $this->cta = Fragment::where('slug', 'cta')->firstOrFail();

    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setCurrentPanel(Filament::getPanel('panel'));
});

function actAsPanelTenant(Tenant $tenant): void
{
    Filament::setTenant($tenant);
    app(CurrentTenant::class)->set($tenant);
}

function pageWithFragmentBlock(Tenant $tenant, ?string $slug): Content
{
    return Content::factory()->for($tenant)->create([
        'content_type' => 'default.page',
        'blocks' => [['type' => 'section', 'data' => ['active' => true, 'blocks' => [
            ['type' => 'fragment', 'data' => ['active' => true, 'slug' => $slug]],
        ]]]],
    ]);
}

it('is an opt-in block, not part of the default set', function () {
    expect(Cms::defaultBlocks())->not->toContain(FragmentBlock::class);
});

it('renders the fragment on the site, inherited ones included', function () {
    $render = fn (Tenant $tenant, string $slug): string => Blade::render(
        '<x-block::fragment :data="$data" :tenant="$tenant" />',
        ['data' => ['slug' => $slug], 'tenant' => $tenant],
    );

    expect($render($this->marketing, 'cta'))->toContain('Reusable component (fragment).')
        ->and($render($this->acme, 'cta'))->toContain('Reusable component (fragment).')
        ->and(trim($render($this->marketing, 'gibt-es-nicht')))->toBe('');
});

it('renders nothing without a resolvable tenant', function () {
    app(CurrentTenant::class)->forget();

    expect(trim(Blade::render('<x-block::fragment :data="$data" :tenant="null" />', ['data' => ['slug' => 'cta']])))
        ->toBe('');
});

function renderFragmentBlock(Tenant $tenant, string $slug): string
{
    return Blade::render('<x-block::fragment :data="$data" :tenant="$tenant" />', ['data' => ['slug' => $slug], 'tenant' => $tenant]);
}

it('renders a fragment that ends up embedding itself only once', function () {
    $embedding = fn (string $text, string $slug): array => [
        ['type' => 'text', 'data' => ['active' => true, 'content' => "<p>{$text}</p>"]],
        ['type' => 'fragment', 'data' => ['active' => true, 'slug' => $slug]],
    ];

    // Directly (itself) and through another fragment (a → b → a).
    Fragment::create(['tenant_id' => $this->marketing->getKey(), 'title' => 'Selbst', 'slug' => 'selbst', 'blocks' => $embedding('Selbst-Inhalt', 'selbst')]);
    Fragment::create(['tenant_id' => $this->marketing->getKey(), 'title' => 'A', 'slug' => 'a', 'blocks' => $embedding('A-Inhalt', 'b')]);
    Fragment::create(['tenant_id' => $this->marketing->getKey(), 'title' => 'B', 'slug' => 'b', 'blocks' => $embedding('B-Inhalt', 'a')]);

    $self = renderFragmentBlock($this->marketing, 'selbst');
    $loop = renderFragmentBlock($this->marketing, 'a');

    expect(substr_count($self, 'Selbst-Inhalt'))->toBe(1)
        ->and(substr_count($loop, 'A-Inhalt'))->toBe(1)
        ->and(substr_count($loop, 'B-Inhalt'))->toBe(1)
        // Nothing stays marked once the page is done.
        ->and(FragmentBlock::isRendering(Fragment::where('slug', 'a')->firstOrFail()))->toBeFalse();
});

it('resolves the layout presets used only inside the fragment', function () {
    $twoColumns = LayoutPreset::where('title', 'Two columns')->firstOrFail();

    Fragment::create(['tenant_id' => $this->marketing->getKey(), 'title' => 'Raster', 'slug' => 'raster', 'blocks' => [
        ['type' => 'section', 'data' => ['active' => true, 'layout_preset_ids' => [$twoColumns->getKey()], 'blocks' => [
            ['type' => 'text', 'data' => ['active' => true, 'content' => '<p>Spalte</p>']],
        ]]],
    ]]);

    // The page preloaded only its own blocks, none of which uses the preset.
    expect(renderFragmentBlock($this->marketing, 'raster'))->toContain('md:grid-cols-2');
});

it('keeps the page jump anchors off the fragment blocks', function () {
    // The page's navigation context indexes the PAGE's root blocks; handed on, the
    // fragment's first block would get the page's first anchor as a duplicate id.
    $html = Blade::render('<x-block::fragment :data="$data" :tenant="$tenant" :navigation-context="$context" />', [
        'data' => ['slug' => 'cta'],
        'tenant' => $this->marketing,
        'context' => ['blockAnchors' => [0 => ['id' => 'seitenanker', 'label' => 'Seite', 'href' => '#seitenanker']]],
    ]);

    expect($html)->toContain('Reusable component (fragment).')
        ->and($html)->not->toContain('id="seitenanker"');
});

it('links the preview of an own fragment to its edit page', function () {
    actAsPanelTenant($this->marketing);

    Livewire::test(EditContent::class, ['record' => pageWithFragmentBlock($this->marketing, 'cta')->getKey()])
        ->assertOk()
        ->assertSeeText('Global CTA (cta)')
        ->assertSee('Fragment bearbeiten')
        ->assertSeeHtml('href="'.FragmentResource::getUrl('edit', ['record' => $this->cta]).'"');
});

it('says so when a fragment is still empty, and still links to it', function () {
    actAsPanelTenant($this->marketing);
    Fragment::create(['tenant_id' => $this->marketing->getKey(), 'title' => 'Leer', 'slug' => 'leer', 'blocks' => []]);

    Livewire::test(EditContent::class, ['record' => pageWithFragmentBlock($this->marketing, 'leer')->getKey()])
        ->assertSee('noch leer, auf der Website unsichtbar')
        ->assertSee('Fragment bearbeiten');
});

it('points a slug that matches no fragment to the fragment list', function () {
    actAsPanelTenant($this->marketing);

    Livewire::test(EditContent::class, ['record' => pageWithFragmentBlock($this->marketing, 'gibt-es-nicht')->getKey()])
        ->assertSee('„gibt-es-nicht“ nicht gefunden')
        ->assertDontSee('Fragment bearbeiten')
        ->assertSee('Fragmente verwalten')
        ->assertSeeHtml('href="'.FragmentResource::getUrl('index').'"');
});

it('says the site keeps showing the inherited fragment while an own one is empty', function () {
    actAsPanelTenant($this->acme);
    Fragment::create(['tenant_id' => $this->acme->getKey(), 'title' => 'Eigene CTA', 'slug' => 'cta', 'blocks' => []]);

    Livewire::test(EditContent::class, ['record' => pageWithFragmentBlock($this->acme, 'cta')->getKey()])
        ->assertSeeText('Eigene CTA (cta)')
        ->assertSee('noch leer, die Website zeigt solange das Fragment von '.$this->marketing->name)
        ->assertDontSee('auf der Website unsichtbar');
});

it('offers no edit link into a tenant panel the user may not enter', function () {
    $this->actingAs(User::where('email', 'admin-b@example.test')->firstOrFail());
    actAsPanelTenant($this->acme);

    Livewire::test(EditContent::class, ['record' => pageWithFragmentBlock($this->acme, 'cta')->getKey()])
        ->assertSee('geerbt von '.$this->marketing->name)
        ->assertDontSee('Fragment bearbeiten');
});

it('marks an inherited fragment and links to it in its own tenant panel', function () {
    actAsPanelTenant($this->acme);

    Livewire::test(EditContent::class, ['record' => pageWithFragmentBlock($this->acme, 'cta')->getKey()])
        ->assertSee('geerbt von '.$this->marketing->name)
        ->assertSeeHtml('href="'.FragmentResource::getUrl('edit', ['record' => $this->cta], tenant: $this->marketing).'"');
});

it('offers own and inherited fragments and keeps an unknown slug selectable', function () {
    Fragment::create(['tenant_id' => $this->acme->getKey(), 'title' => 'Eigene Box', 'slug' => 'box', 'blocks' => []]);

    $options = (new ReflectionMethod(FragmentBlock::class, 'fragmentOptions'))->invoke(null, $this->acme, 'tippfehler');

    expect($options)->toBe([
        'box' => 'Eigene Box (box)',
        'cta' => 'Global CTA (cta) — geerbt',
        'tippfehler' => '„tippfehler“ — nicht gefunden',
    ]);
});

it('leaves empty inherited fragments and the edited fragment itself out of the picker', function () {
    Fragment::create(['tenant_id' => $this->marketing->getKey(), 'title' => 'Leer', 'slug' => 'leer', 'blocks' => []]);
    $options = new ReflectionMethod(FragmentBlock::class, 'fragmentOptions');

    // acme inherits only what marketing's site would serve: the empty one is not.
    expect($options->invoke(null, $this->acme, null))->toHaveKey('cta')->not->toHaveKey('leer')
        // Editing `cta` itself: it cannot embed itself…
        ->and($options->invoke(null, $this->marketing, null, $this->cta))->not->toHaveKey('cta')->toHaveKey('leer')
        // …but a block that already does keeps showing what it points at.
        ->and($options->invoke(null, $this->marketing, 'cta', $this->cta))->toHaveKey('cta');
});

it('names the fragment in the row title and keeps the plain label for the picker', function () {
    $label = new ReflectionMethod(FragmentBlock::class, 'rowLabel');

    expect((string) $label->invoke(null, ['slug' => 'cta'], 'item-key', $this->marketing))
        ->toBe('Global CTA <span class="fi-builder-title-suffix">Fragment</span>')
        ->and($label->invoke(null, null, null, $this->marketing))->toBe('Fragment')
        ->and($label->invoke(null, ['slug' => ''], 'item-key', $this->marketing))->toBe('Fragment');
});
