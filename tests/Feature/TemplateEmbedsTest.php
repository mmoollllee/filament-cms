<?php

/*
 * "Außerdem auf dieser Seite": a template declares the fragments and record lists
 * it renders besides the page's blocks (Cms::templateEmbeds()), and the content
 * form links to where that content is maintained — content an editor sees on the
 * page but finds nowhere in its builder. Keyed by the resolved view name; `*`
 * applies to every page — and only to pages: a type without one of its own
 * (marketing.note) has no footer to embed anything in.
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Filament\Support\ManagementLinks;
use Mmoollllee\Cms\Support\Content\TemplateResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

it('adds up declarations per view and merges in the every-page ones', function () {
    Cms::templateEmbeds('content.page', fragments: ['cta']);
    Cms::templateEmbeds(['content.page', 'content.other'], contentTypes: ['marketing.service']);
    Cms::templateEmbeds('*', fragments: ['footer', 'cta']);

    expect(Cms::embedsForTemplate('content.page'))->toBe(['fragments' => ['cta', 'footer'], 'contentTypes' => ['marketing.service']])
        ->and(Cms::embedsForTemplate('content.other'))->toBe(['fragments' => ['footer', 'cta'], 'contentTypes' => ['marketing.service']])
        ->and(Cms::embedsForTemplate('content.none'))->toBe(['fragments' => ['footer', 'cta'], 'contentTypes' => []]);
});

it('leaves the every-page declarations out on request', function () {
    Cms::templateEmbeds('content.page', fragments: ['cta']);
    Cms::templateEmbeds('*', fragments: ['footer']);

    expect(Cms::embedsForTemplate('content.page', includeEveryPage: false))->toBe(['fragments' => ['cta'], 'contentTypes' => []])
        ->and(Cms::embedsForTemplate('content.none', includeEveryPage: false))->toBe(['fragments' => [], 'contentTypes' => []]);
});

it('forgets every declaration on flush', function () {
    Cms::templateEmbeds('*', fragments: ['footer']);

    expect(Cms::hasTemplateEmbeds())->toBeTrue();

    Cms::flush();

    expect(Cms::hasTemplateEmbeds())->toBeFalse()
        ->and(Cms::embedsForTemplate('content.page'))->toBe(['fragments' => [], 'contentTypes' => []]);
});

it('resolves a view name from form state exactly as from the record', function () {
    $tenant = Tenant::factory()->create(['site_key' => 'marketing']);
    $page = Content::factory()->for($tenant)->create(['content_type' => 'default.page', 'template' => null]);
    $resolver = app(TemplateResolver::class);

    expect($resolver->resolveName('default.page', null, $tenant))->toBe($resolver->resolve($page, $tenant))
        ->and($resolver->resolveName('default.page', 'content.gibt-es-nicht', $tenant))->toBe('content.gibt-es-nicht');
});

describe('the content form box', function () {
    beforeEach(function () {
        $this->seed(DatabaseSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('panel'));

        $this->tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

        $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
        Filament::setTenant($this->tenant);
        app(CurrentTenant::class)->set($this->tenant);

        $this->page = Content::factory()->for($this->tenant)->create(['content_type' => 'default.page', 'template' => null]);
        $this->view = app(TemplateResolver::class)->resolve($this->page, $this->tenant);
    });

    it('links the fragments and record lists the template embeds', function () {
        Cms::templateEmbeds($this->view, fragments: ['cta', 'fehlt'], contentTypes: ['marketing.service']);

        $cta = Fragment::where('slug', 'cta')->firstOrFail();
        $services = ManagementLinks::forContentType('marketing.service', $this->tenant);

        Livewire::test(EditContent::class, ['record' => $this->page->getKey()])
            ->assertOk()
            ->assertSee('Außerdem auf dieser Seite')
            ->assertSee('Fragment „Global CTA“')
            ->assertSeeHtml('href="'.FragmentResource::getUrl('edit', ['record' => $cta]).'"')
            // A declared fragment that does not exist yet: the way to create it.
            ->assertSee('Fragment „fehlt“ anlegen')
            ->assertSeeHtml('href="'.FragmentResource::getUrl('index').'"')
            ->assertSee($services['label'])
            ->assertSeeHtml('href="'.e($services['url']).'"');
    });

    it('stays hidden when the template embeds nothing', function () {
        Cms::templateEmbeds('content.somewhere-else', fragments: ['cta']);

        Livewire::test(EditContent::class, ['record' => $this->page->getKey()])
            ->assertOk()
            ->assertDontSee('Außerdem auf dieser Seite');
    });

    it('keeps the every-page embeds off types without a page of their own', function () {
        Cms::templateEmbeds('*', fragments: ['cta']);

        $note = Content::create([
            'tenant_id' => $this->tenant->getKey(),
            'content_type' => 'marketing.note',
            'title' => 'Notiz',
            'slug' => 'notiz',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subDay(),
        ]);

        Livewire::test(EditContent::class, ['record' => $note->getKey()])
            ->assertOk()
            ->assertDontSee('Außerdem auf dieser Seite');

        Livewire::test(EditContent::class, ['record' => $this->page->getKey()])
            ->assertSee('Außerdem auf dieser Seite')
            ->assertSee('Fragment „Global CTA“');
    });

    it('follows the template picked in the form', function () {
        Cms::templateEmbeds('content.sonderseite', fragments: ['cta']);

        Livewire::test(EditContent::class, ['record' => $this->page->getKey()])
            ->assertDontSee('Außerdem auf dieser Seite')
            ->set('data.template', 'content.sonderseite')
            ->assertSee('Außerdem auf dieser Seite')
            ->assertSee('Fragment „Global CTA“');
    });
});

it('answers null for a content type no resource manages', function () {
    $tenant = Tenant::factory()->create(['site_key' => 'marketing']);

    expect(ManagementLinks::forContentType('does.not.exist', $tenant))->toBeNull();
});
