<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Models\Menu;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

/**
 * Seeds the testbench frontend as a **marketing + documentation website for the
 * filament-cms package** — built with filament-cms itself (dogfooding). Visiting
 * the site IS the documentation: live block demos + code snippets showing how each
 * feature is used.
 *
 *  Tenant A · "filament-cms"  · site_key 'marketing' · http://127.0.0.1:8000
 *     The docs site: Home (marketing + the self-doc that was docs/DEMO.md — tenants,
 *     logins, feature matrix), Features (with code), Blocks (live block showcase),
 *     Customize (the customization guide as content) and the HowTos (custom blocks —
 *     proven live by the workbench's own HintBlock — and TipTap extensions).
 *  Tenant B · "Acme GmbH"     · site_key 'acme'      · http://localhost:8000
 *     Inherits filament-cms's branding (multi-tenancy proof) AND demonstrates the
 *     onepager: its frontend is composed of default.section contents, each with its
 *     own path, all rendering the same shell.
 *
 *  Panel: /panel. The Login page prefills the demo superadmin in local env; all
 *  users share the password "password".
 */
class DatabaseSeeder extends Seeder
{
    /** section-header preset id, reused by the section headers seeded below. */
    protected ?int $headerPresetId = null;

    public function run(): void
    {
        // Idempotency guard: fixed identifiers (emails, domains) would clash on re-run.
        if (Tenant::query()->exists()) {
            return;
        }

        $users = $this->seedUsers();
        $presets = $this->seedLayoutPresets();
        $this->headerPresetId = $presets['header']->id;

        $this->seedDocsTenant($users, $presets);
        $this->seedSecondTenant($users);
    }

    // ── Users & roles ─────────────────────────────────────────────────────────
    // Superadmin (all tenants) + per-tenant Admin/Editor + a multi-tenant user.
    // Exactly one superadmin (the seeder test relies on that).

    /** @return array<string, User> */
    protected function seedUsers(): array
    {
        return [
            'superadmin' => User::factory()->superadmin()->create(['name' => 'Super Admin', 'email' => 'admin@example.test']),
            'adminA' => User::factory()->create(['name' => 'Dana Docs', 'email' => 'admin-a@example.test']),
            'editorA' => User::factory()->create(['name' => 'Erik Editor', 'email' => 'editor-a@example.test']),
            'adminB' => User::factory()->create(['name' => 'Bea Acme', 'email' => 'admin-b@example.test']),
            'multiTenant' => User::factory()->create(['name' => 'Max Multi', 'email' => 'multi@example.test']),
        ];
    }

    // ── Layout presets ────────────────────────────────────────────────────────
    // Reusable Tailwind class-sets selectable on content/blocks. `scope` controls
    // where each is offered; null tenant_id = global. The classes here are the ones
    // the demo frontend stylesheet implements.

    /** @return array<string, LayoutPreset> */
    protected function seedLayoutPresets(): array
    {
        $make = fn (array $scope, string $type, string $title, string $classes): LayoutPreset => LayoutPreset::create(
            compact('scope', 'type', 'title', 'classes') + ['tenant_id' => null],
        );

        return [
            'narrow' => $make(['content'], 'Width', 'Narrow content', 'max-w-3xl mx-auto'),
            'twoCol' => $make(['section'], 'Columns', 'Two columns', 'md:grid-cols-2'),
            'threeCol' => $make(['section'], 'Columns', 'Three columns', 'md:grid-cols-3'),
            'header' => $make(['section-header'], 'Header', 'Standard header (left)', 'w-full'),
            'centeredHeader' => $make(['section-header'], 'Header', 'Centered header', 'text-center mx-auto max-w-2xl'),
            'spanTwo' => $make(['section-child'], 'Columns', 'Across two columns', 'md:col-span-2'),
            'cardGrid' => $make(['listing-wrapper'], 'Listing', 'Card grid', 'grid sm:grid-cols-2 lg:grid-cols-3 gap-6'),
        ];
    }

    // ── Tenant A: the filament-cms docs site ──────────────────────────────────

    /**
     * @param  array<string, User>  $users
     * @param  array<string, LayoutPreset>  $presets
     */
    protected function seedDocsTenant(array $users, array $presets): Tenant
    {
        $tenant = Tenant::factory()->withSiteSettings()->create([
            'name' => 'filament-cms',
            'site_key' => 'marketing',
            'primary_domain' => '127.0.0.1',
            'brand_name' => 'filament-cms',
            'brand_claim' => 'The multi-tenant CMS toolkit for Filament',
            'primary_color' => '#d97706',
            'company_name' => 'filament-cms',
            'contact_email' => 'hi@filament-cms.test',
            'footer_text' => 'filament-cms — the multi-tenant CMS engine for Filament.',
            'default_seo_title' => 'filament-cms – CMS toolkit for Filament',
            'default_seo_description' => 'filament-cms is a multi-tenant CMS engine for Filament: content types, block builder, layout presets, fragments and more.',
            'social_links' => [
                ['network' => 'linkedin', 'url' => 'https://linkedin.com'],
                ['network' => 'instagram', 'url' => 'https://instagram.com'],
            ],
            'spam_questions' => [
                ['question' => 'What is the admin framework this is built on?', 'answer' => 'filament'],
            ],
        ]);

        $tenant->users()->attach($users['superadmin'], ['role' => TenantUserRole::Admin->value]);
        $tenant->users()->attach($users['adminA'], ['role' => TenantUserRole::Admin->value]);
        $tenant->users()->attach($users['editorA'], ['role' => TenantUserRole::Editor->value]);
        $tenant->users()->attach($users['multiTenant'], ['role' => TenantUserRole::Editor->value]);

        $this->seedHome($tenant, $presets);
        $this->seedFeatures($tenant, $presets);
        $this->seedBlocks($tenant, $presets);
        $this->seedCustomize($tenant, $presets);
        $this->seedHowtos($tenant, $presets);
        $this->seedPublishingDemos($tenant);
        $this->seedLegal($tenant);

        // Fragment (reusable block group) — inherited by tenant B via the cascade.
        Fragment::create([
            'tenant_id' => $tenant->id,
            'title' => 'Global CTA',
            'slug' => 'cta',
            'blocks' => [['type' => 'text', 'data' => ['active' => true, 'content' => '<p>Reusable component (fragment).</p>']]],
        ]);

        $this->seedMenu($tenant, 'header', 'Main menu', [
            ['title' => 'Home', 'url' => '/'],
            ['title' => 'Features', 'url' => '/features'],
            ['title' => 'Blocks', 'url' => '/blocks'],
            ['title' => 'Customize', 'url' => '/customize'],
            ['title' => 'HowTos', 'url' => '/howto'],
        ]);
        $this->seedMenu($tenant, 'footer', 'Footer', [
            ['title' => 'Features', 'url' => '/features'],
            ['title' => 'Blocks', 'url' => '/blocks'],
            ['title' => 'Customize', 'url' => '/customize'],
            ['title' => 'HowTos', 'url' => '/howto'],
            ['title' => 'Imprint', 'url' => '/imprint'],
            ['title' => 'Privacy', 'url' => '/privacy'],
        ]);

        return $tenant;
    }

    /** @param array<string, LayoutPreset> $presets */
    protected function seedHome(Tenant $tenant, array $presets): void
    {
        $home = Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'title' => 'filament-cms',
            'path' => '/',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subWeek(),
            'payload' => ['hero' => [
                'title' => 'The multi-tenant CMS toolkit for Filament',
                // Deliberately the OLDEST wording — the two applied edits below
                // grow it into the final text, so the demo ships a real,
                // diffable version history (see the end of this method).
                'subtitle' => 'Build multi-tenant websites with content types, a block builder and layout presets — fully integrated into Filament.',
                'cta_label' => 'Explore the features',
                'cta_url' => '/features',
            ]],
            'meta' => ['seo_title' => 'filament-cms – CMS toolkit for Filament'],
            'blocks' => [
                // Feature highlights → navigation cards (rich-editor custom block).
                $this->section([
                    ['type' => 'text', 'data' => ['active' => true, 'content' => $this->navCards([
                        ['label' => 'Multi-tenant', 'href' => '/features', 'text' => 'Domain-based tenants with branding inheritance.'],
                        ['label' => 'Block builder', 'href' => '/blocks', 'text' => 'Pages built from reusable, nestable blocks.'],
                        ['label' => 'Extendable', 'href' => '/customize', 'text' => 'Blueprints, presets, fragments — every extension point documented.'],
                        ['label' => 'HowTos', 'href' => '/howto', 'text' => 'Custom blocks and TipTap extensions, step by step.'],
                    ])]],
                ], [
                    'eyebrow' => 'Overview',
                    'title' => 'Everything a website needs',
                    'heading' => 'h2',
                    'header_preset_ids' => [$presets['centeredHeader']->id],
                ]),
                // Quick start: code + CTA buttons, one section.
                $this->section([
                    ['type' => 'text', 'data' => ['active' => true, 'content' => $this->code('bash',
                        "composer require mmoollllee/filament-cms\n\nphp artisan cms:install   # config + migrations + models + panel + routes\nphp artisan migrate"
                    )]],
                    ['type' => 'text', 'data' => ['active' => true, 'content' => $this->buttons('center', [
                        ['label' => 'Features', 'href' => '/features', 'variant' => 'primary', 'size' => 'lg'],
                        ['label' => 'Block showcase', 'href' => '/blocks', 'variant' => 'secondary', 'size' => 'lg'],
                    ])]],
                ], [
                    'title' => 'Quick start',
                    'heading' => 'h2',
                    'header_preset_ids' => [$presets['header']->id],
                    'content' => '<p>Install via Composer, scaffold with one command, get going — <code>cms:install</code> publishes config + migrations, writes the models (on the package traits), the panel provider and the frontend routes:</p>',
                ]),
                // Self-documentation (tenants, logins, feature matrix — the former
                // docs/DEMO.md): ONE section, flat text children with own titles.
                $this->section($this->docTexts(), [
                    'title' => 'Documentation',
                    'heading' => 'h2',
                    'header_preset_ids' => [$presets['header']->id],
                    'content' => '<p>This site is a <strong>marketing + documentation site for filament-cms, built with filament-cms</strong> (dogfooding). Everything here is real CMS content from the seeder — each page demonstrates an engine feature.</p>',
                ]),
            ],
        ]);

        // Live demo for the versioning history: two APPLIED edits on top of the
        // initial version, so the home page's "Revisionen" action offers a real
        // side-by-side diff and the dashboard widget lists actual changes.
        // (Applied edits only — the draft demo on /features stays out of this.)
        $home->update(['payload' => ['hero' => [
            'title' => 'The multi-tenant CMS toolkit for Filament',
            'subtitle' => 'Build multi-tenant websites with content types, a block builder, layout presets and fragments — fully integrated into Filament.',
            'cta_label' => 'Explore the features',
            'cta_url' => '/features',
        ]]]);

        $home->update(['payload' => ['hero' => [
            'title' => 'The multi-tenant CMS toolkit for Filament',
            'subtitle' => 'Build multi-tenant websites with content types, a block builder, layout presets, fragments, drafts with preview and full version history — fully integrated into Filament.',
            'cta_label' => 'Explore the features',
            'cta_url' => '/features',
        ]]]);
    }

    protected function seedFeatures(Tenant $tenant, array $presets): void
    {
        $features = Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'title' => 'Features',
            'path' => '/features',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subWeek(),
            'sort' => 10,
            'layout_preset_ids' => [$presets['narrow']->id],
            'meta' => ['seo_description' => 'The features of filament-cms with code examples.'],
            'payload' => ['hero' => ['subtitle' => 'Each feature with an explanation and code — how it is used.']],
            'blocks' => [$this->section([
                $this->textChild('Multi-tenancy', '<p>Multiple websites in one installation, resolved by the request <strong>domain</strong>. Content, menus and presets are separated per tenant.</p>'.$this->code('php', <<<'PHP'
                    // PanelProvider: resolve tenancy by domain
                    $panel
                        ->tenant(Cms::tenantModel(), slugAttribute: 'primary_domain')
                        ->tenantDomain('{tenant:primary_domain}');
                    PHP)),
                $this->textChild('Content types & blueprints', '<p>Every content type is a <strong>blueprint</strong>: routable?, onepager?, allowed parents, template, fields. Auto-discovered under <code>app/Sites</code>.</p>'.$this->code('php', <<<'PHP'
                    class Blueprint extends ConfiguredContentBlueprint
                    {
                        protected string $key = 'marketing.service';
                        protected string $label = 'Service';
                        protected bool $isRoutable = false;
                        protected array $allowedParentTypes = ['default.section'];
                    }
                    PHP)),
                $this->textChild('Onepager & sections', '<p>A site can compose its frontend as an <strong>onepager</strong>: root contents of type <code>default.section</code> each carry their own path, and every path renders the same shell of stacked sections — see it live on <a href="http://localhost:8000">tenant B</a>. The Seite/Sektion choice on the content form is a <strong>blueprint flag</strong>: an onepager site re-declares <code>default.section</code> (same key → overrides the package blueprint for that site) with the flag on — exactly what this demo\'s <code>Acme</code> site extension does:</p>'.$this->code('php', <<<'PHP'
                    // app/Sites/Acme/Section/Blueprint.php — discovered like any site blueprint;
                    // same key ('default.section') → overrides the package blueprint for this site.
                    class Blueprint extends \Mmoollllee\Cms\Sites\Default\Section\Blueprint
                    {
                        protected bool $offeredInTypeSelect = true;
                    }
                    PHP)),
                $this->textChild('Block builder', '<p>Pages consist of blocks (section, text, media, listing) that can be nested. Stored as JSON in the <code>blocks</code> field.</p>'.$this->code('php', <<<'PHP'
                    Content::create([
                        'content_type' => 'default.page',
                        'blocks' => [
                            ['type' => 'section', 'data' => [
                                'title' => 'Hello world',
                                'blocks' => [
                                    ['type' => 'text', 'data' => ['content' => '<p>…</p>']],
                                ],
                            ]],
                        ],
                    ]);
                    PHP)),
                $this->textChild('Layout presets', '<p>Reusable Tailwind class sets, selectable on content and blocks — controlled via <code>scope</code>.</p>'.$this->code('php', <<<'PHP'
                    LayoutPreset::create([
                        'scope'   => ['section'],
                        'type'    => 'Columns',
                        'title'   => 'Two columns',
                        'classes' => 'md:grid-cols-2',
                    ]);
                    PHP)),
                $this->textChild('Fragments', '<p>Reusable block groups, addressable by slug — they follow branding inheritance (own tenant → branding tenant).</p>'.$this->code('php', <<<'PHP'
                    // In a template or block:
                    $blocks = fragment_model('cta')?->blocks;
                    PHP)),
                $this->textChild('Publishing & SEO', '<p>State from <code>publish_from</code>/<code>publish_until</code> + visibility. SEO per content via <code>meta.*</code>.</p>'.$this->code('php', <<<'PHP'
                    Content::create([
                        'visibility'   => ContentVisibility::Members,   // members only
                        'publish_from' => now()->addWeek(),             // scheduled
                        'meta' => [
                            'seo_title'   => 'My title',
                            'noindex'     => true,
                        ],
                    ]);
                    PHP)),
                $this->textChild('Drafts & Vorschau', '<p><strong>„Entwurf speichern"</strong> stashes the validated form state in the record\'s <code>draft</code> column — the live site keeps serving the applied version until <strong>„Änderungen anwenden"</strong>. The <strong>Vorschau</strong> action saves the draft first, then opens the session-sticky preview mode (<code>?preview=1</code>, superadmins/members only): every retrieved content <em>and fragment</em> overlays its pending draft, wherever it renders — own page, listings, onepager sections, embedded fragments. <strong>Try it live:</strong> this very page carries a pending draft. <a href="/panel">Log in</a>, then open <a href="/features?preview=1">/features?preview=1</a> — an extra section appears; the floating badge leaves the mode.</p>'.$this->code('php', <<<'PHP'
                    class Content extends Model implements ContentContract
                    {
                        use HasDraft;   // same trait on the Fragment model
                    }

                    // Panel: „Entwurf speichern" / „Änderungen anwenden" / Vorschau (eye).
                    // Frontend: ?preview=1 enters, ?preview=0 (or the badge) leaves.
                    PHP)),
                $this->textChild('Versioning & restore', '<p>Every <strong>applied</strong> change records a snapshot version (create, „Änderungen anwenden", restores) — browse them via the edit page\'s <strong>Revisionen</strong> action with a side-by-side diff and restore any state. Drafts stay out of the history by design: stashing creates no version and the <code>draft</code> column is excluded from snapshots. The dashboard\'s <strong>„Letzte Änderungen"</strong> widget lists the tenant\'s recent changes across contents and fragments with author and deep links.</p>'.$this->code('php', <<<'PHP'
                    class Content extends Model implements ContentContract
                    {
                        use HasDraft;
                        use HasVersions;   // snapshot per applied change; draft/sort excluded
                    }

                    // Resource: 'revisions' => Revisions::route('/{record}/revisions')
                    // Restore discards a pending draft and records a new version.
                    PHP)),
            ])],
        ]);

        // Live demo for the draft workflow: the features page ships WITH a
        // pending draft, so /features?preview=1 (logged in) shows this extra
        // section while guests keep seeing the applied version above.
        $features->stashDraft([
            'title' => $features->title,
            'blocks' => [
                ...$features->blocks,
                $this->section([
                    $this->textChild('This section exists only in the pending draft', '<p>You are looking at the <strong>draft preview</strong>: the extra section is stashed in the page\'s <code>draft</code> column and never rendered for guests. Leave via <strong>„Beenden"</strong> on the floating badge (or <code>?preview=0</code>) — apply or discard the draft on the <a href="/panel">edit page</a>.</p>'),
                ], ['title' => 'Draft preview — live demo', 'heading' => 'h2', 'header_preset_ids' => [$presets['header']->id]]),
            ],
        ]);
    }

    protected function seedBlocks(Tenant $tenant, array $presets): void
    {
        // Example records the Listing-block demo will list (a non-routable type).
        foreach (['Quick start', 'Configuration', 'Custom blocks'] as $i => $title) {
            Content::create([
                'tenant_id' => $tenant->id,
                'content_type' => 'marketing.service',
                'title' => $title,
                'visibility' => ContentVisibility::Public,
                'publish_from' => now()->subWeek(),
                'sort' => $i,
            ]);
        }

        Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'title' => 'Blocks',
            'path' => '/blocks',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subWeek(),
            'sort' => 20,
            'payload' => ['hero' => ['subtitle' => 'The bundled builder blocks — each rendered live, next to the JSON that produces it.']],
            'blocks' => [
                // SECTION + TEXT: this showcase section IS the live demo — two-column
                // preset on itself, the code text spans both columns (child preset).
                $this->section([
                    ['type' => 'text', 'data' => ['active' => true, 'content' => '<h3>Column one</h3><p>Text block with rich text: <strong>bold</strong>, <em>italic</em>, lists, links.</p><ul><li>Item A</li><li>Item B</li></ul>']],
                    ['type' => 'text', 'data' => ['active' => true, 'content' => '<h3>Column two</h3><p>Layout via preset <code>md:grid-cols-2</code>.</p>']],
                    ['type' => 'text', 'data' => ['active' => true, 'layout_preset_ids' => [$presets['spanTwo']->id], 'content' => $this->code('php', "['type' => 'section', 'data' => [\n    'layout_preset_ids' => [\$twoColPreset],\n    'blocks' => [\n        ['type' => 'text', 'data' => ['content' => '<h3>Column one</h3>…']],\n        ['type' => 'text', 'data' => ['content' => '<h3>Column two</h3>…']],\n    ],\n]]")]],
                ], [
                    'title' => 'Section & text',
                    'heading' => 'h2',
                    'header_preset_ids' => [$this->headerPresetId],
                    'content' => '<p>A <strong>section</strong> is a container with a layout preset and child blocks — this very section uses the two-column preset:</p>',
                    'layout_preset_ids' => [$presets['twoCol']->id],
                ]),
                // MEDIA
                $this->section([
                    ['type' => 'media', 'data' => ['active' => true, 'media_path' => '/demo-media.svg', 'media_alt' => 'Media block demo']],
                    $this->textChild(null, $this->code('php', "['type' => 'media', 'data' => [\n    'media_path' => 'tenants/…/image.jpg',\n    'media_alt'  => 'Description',\n]]")),
                ], [
                    'title' => 'Media',
                    'heading' => 'h2',
                    'header_preset_ids' => [$this->headerPresetId],
                    'content' => '<p>The <strong>media</strong> block shows an image or video (with an optional poster):</p>',
                ]),
                // LISTING
                $this->section([
                    ['type' => 'listing', 'data' => ['active' => true, 'content_type' => 'marketing.service', 'wrapper_preset_ids' => [$presets['cardGrid']->id]]],
                    $this->textChild(null, $this->code('php', "['type' => 'listing', 'data' => [\n    'content_type'       => 'marketing.service',\n    'wrapper_preset_ids' => [\$cardGridPreset],\n]]")),
                ], [
                    'title' => 'Listing',
                    'heading' => 'h2',
                    'header_preset_ids' => [$this->headerPresetId],
                    'content' => '<p>The <strong>listing</strong> block lists visible content of a type (here <code>marketing.service</code>) as cards:</p>',
                ]),
                // BUTTON GROUP (rich-editor)
                $this->section([
                    ['type' => 'text', 'data' => ['active' => true, 'content' => $this->buttons('start', [
                        ['label' => 'Primary', 'href' => '/features', 'variant' => 'primary'],
                        ['label' => 'Secondary', 'href' => '/features', 'variant' => 'secondary'],
                        ['label' => 'Soft', 'href' => '/features', 'variant' => 'soft'],
                    ])]],
                    $this->textChild(null, $this->code('php', "// Embedded in the rich editor (TipTap customBlock 'buttonGroup')\n['alignment' => 'start', 'buttons' => [\n    ['label' => 'Primary',   'href' => '/…', 'variant' => 'primary'],\n    ['label' => 'Secondary', 'href' => '/…', 'variant' => 'secondary'],\n]]")),
                ], [
                    'title' => 'Button group',
                    'heading' => 'h2',
                    'header_preset_ids' => [$this->headerPresetId],
                    'content' => '<p>Rich-editor block component — styled CTAs in several variants:</p>',
                ]),
                // NAV CARDS (rich-editor)
                $this->section([
                    ['type' => 'text', 'data' => ['active' => true, 'content' => $this->navCards([
                        ['label' => 'Features', 'href' => '/features', 'text' => 'All features with code.'],
                        ['label' => 'Home', 'href' => '/', 'text' => 'Back to the overview.'],
                    ])]],
                    $this->textChild(null, $this->code('php', "// TipTap customBlock 'navigationCardGroup'\n['cards' => [\n    ['label' => 'Features', 'href' => '/features', 'text' => '…'],\n]]")),
                ], [
                    'title' => 'Navigation cards',
                    'heading' => 'h2',
                    'header_preset_ids' => [$this->headerPresetId],
                    'content' => '<p>Rich-editor block component — linked cards with a title and text:</p>',
                ]),
            ],
        ]);
    }

    /**
     * The customization guide (docs/CUSTOMIZATION.md) as seeded CMS content — every
     * extension point in one page, each with a code snippet.
     *
     * @param  array<string, LayoutPreset>  $presets
     */
    protected function seedCustomize(Tenant $tenant, array $presets): void
    {
        Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'title' => 'Customize',
            'path' => '/customize',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subWeek(),
            'sort' => 30,
            'layout_preset_ids' => [$presets['narrow']->id],
            'meta' => ['seo_description' => 'Every extension point of filament-cms — config, models, blueprints, blocks, panel, views.'],
            'payload' => ['hero' => ['subtitle' => 'The package is the updatable baseline — everything project-specific layers on top. Every extension point, with code.']],
            'blocks' => [$this->section([
                $this->textChild('Engine wiring — Cms:: registry', '<p><code>php artisan cms:install</code> scaffolds <code>App\Providers\CmsServiceProvider</code> with the model registration pre-wired. Everything structural is registered in code (Cashier/Sanctum-style) — <code>config/cms.php</code> keeps only environment-driven settings (branding tenant, dev-login prefill, redirect tunables). Panel options (vite theme, path) live on the Panel in your PanelProvider, Filament-style:</p>'.$this->code('php', <<<'PHP'
                    public function register(): void
                    {
                        Cms::useContentModel(App\Models\Content::class);   // required
                        Cms::useTenantModel(App\Models\Tenant::class);     // required
                        Cms::useFragmentModel(App\Models\Fragment::class); // optional

                        // everything else only when it DIFFERS from the package default,
                        // e.g. project blocks in the panel pickers:
                        Cms::registerBlocks([...Cms::defaultBlocks(), /* project blocks */]);
                    }
                    PHP)),
                $this->textChild('Models — contracts + traits', '<p>The engine types against <code>Mmoollllee\Cms\Contracts\*</code> and resolves your concrete models from the <code>Cms::use*Model()</code> registrations. The engine-critical logic ships as traits, so it updates with the package instead of living as per-app copies (<code>cms:install</code> scaffolds exactly these models):</p>'.$this->code('php', <<<'PHP'
                    class Content extends Model implements ContentContract, MenuPanelable
                    {
                        use AssignsCurrentTenant;    // tenant_id from the request host
                        use ConvertsUploadedVideos;  // video re-encode job on save
                        use GeneratesPathAndSlug;    // collision-free path/slug
                        use HasDraft;                // "Entwurf speichern" + Vorschau overlay
                        use HasPublishingStatus;     // status() + visibleTo()/ofType() scopes
                        use HasVersions;             // Snapshot-Historie + Revisionen/Restore
                        use ResolvesLayoutPresets;   // resolvedLayoutPreset()
                    }

                    class Tenant extends Model implements TenantContract
                    {
                        use HasContents;             // contents() + visibleContents()
                        use HasSpamQuestions;        // spam-protection questions
                        use HasTenantUsers;          // users(), hasUser(), isVisibleTo()
                        use InheritsBranding;        // resolved*() branding cascade
                    }

                    class User extends Authenticatable implements UserContract /* + Filament tenancy */
                    {
                        use BelongsToTenants;        // roles + host-aware panel access
                    }
                    PHP)),
                $this->textChild('Site extensions & blueprints', '<p>Content types per <code>site_key</code>, auto-discovered under <code>app/Sites</code>. The <code>default</code> extension (pages + onepager sections) ships in the package:</p>'.$this->code('php', <<<'PHP'
                    class SiteExtension implements \Mmoollllee\Cms\Contracts\SiteExtension
                    {
                        use DiscoversSiteBlueprints;   // <dir>/<Type>/Blueprint.php
                        use DiscoversSiteResources;    // <dir>/<Type>/Resource.php

                        public function siteKey(): string { return 'my-site'; }
                    }
                    PHP)),
                $this->textChild('Resources & pages — 3-liners', '<p>Per-type Filament resources extend <code>TenantScopedContentResource</code>; their pages extend the package base pages and inherit block copy/paste, cross-builder drag &amp; drop, payload-preserving + draft-aware saves (Entwurf/Vorschau) and parent-scoped listings:</p>'.$this->code('php', <<<'PHP'
                    class ListPage extends ContentListPage
                    {
                        protected static string $resource = Resource::class;
                    }
                    // CreatePage extends ContentCreatePage, EditPage extends ContentEditPage
                    PHP)),
                $this->textChild('Field kits', '<p>Composable field sets used across resources — compose, don\'t copy:</p>'.$this->code('php', <<<'PHP'
                    SeoFields::make()->without('og_image')->toArray();
                    PublishingFields::make()->defaultVisibilityUsing(fn () => 'members')->toArray();
                    PageHeaderFields::make()->uploadDirectory("tenants/{$siteKey}/hero")->toArray();
                    PHP)),
                $this->textChild('Shortcodes & merge tags', '<p>Tenant-aware <code>[tokens]</code> in rich text; the RichEditor offers them as merge tags. Project shortcodes register reset-safe:</p>'.$this->code('php', <<<'PHP'
                    Shortcodes::extendDefaultsUsing(function (): void {
                        Shortcodes::register('opening_hours', fn (array $attrs): string => '…');
                        Shortcodes::registerMergeTagValue('opening_hours', fn () => '…');
                    });
                    PHP)),
                $this->textChild('Panel — one thin subclass', '<p>The <code>BasePanelProvider</code> wires resources, pages, tenancy, branding, the RichEditor stack and the login redirect. Apps override hooks only to customize:</p>'.$this->code('php', <<<'PHP'
                    class PanelProvider extends BasePanelProvider
                    {
                        protected function configurePanel(Panel $panel): Panel
                        {
                            return $panel->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages');
                        }
                    }
                    PHP)),
                $this->textChild('Frontend views — fallback chain', '<p>Every frontend view ships as a brand-agnostic fallback: app view → package fallback; site-specific views (<code>{site_key}/errors/404</code>) win over both. Publish to customize:</p>'.$this->code('php', <<<'PHP'
                    // php artisan vendor:publish --tag=cms-frontend        (shells, partials, errors)
                    // php artisan vendor:publish --tag=cms-site-components (<x-site.*>)
                    // php artisan vendor:publish --tag=cms-blocks          (block views)
                    PHP)),
                $this->textChild('Update safety — the two vendored views', '<p>Exactly two Filament views are overridden (builder + block picker) — they carry cross-builder drag &amp; drop, inline preview editing, the inactive-block UI and clipboard paste. A hash-based drift test fails on every Filament update that touches the originals, with re-vendoring instructions:</p>'.$this->code('php', <<<'PHP'
                    // tests/Feature/FilamentViewOverrideDriftTest.php
                    // → fails when filament/forms changes builder.blade.php or block-picker.blade.php
                    // → re-apply the `cms:start` … `cms:end` blocks onto the new vendor copy, update the hash
                    PHP)),
            ])],
        ]);
    }

    /**
     * The HowTo hub + two step-by-step guides: custom builder blocks (with the demo's
     * own HintBlock as living proof) and TipTap extensions.
     *
     * @param  array<string, LayoutPreset>  $presets
     */
    protected function seedHowtos(Tenant $tenant, array $presets): void
    {
        $hub = Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'title' => 'HowTos',
            'path' => '/howto',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subWeek(),
            'sort' => 40,
            'payload' => ['hero' => ['subtitle' => 'Step-by-step guides for the extension points you will actually use.']],
            'blocks' => [
                $this->section([
                    ['type' => 'text', 'data' => ['active' => true, 'content' => $this->navCards([
                        ['label' => 'Custom blocks', 'href' => '/howto/custom-blocks', 'text' => 'Build and register your own builder block — the hint box on that page IS one.'],
                        ['label' => 'TipTap extensions', 'href' => '/howto/tiptap-extensions', 'text' => 'Why the editor needs PHP+JS extension pairs, and how to add your own.'],
                    ])]],
                ]),
            ],
        ]);

        // The guides are CHILDREN of the hub (parent_id): drives the breadcrumb
        // trail, the "Seiten verwalten" action on the hub and parent-driven paths.
        $this->seedCustomBlocksHowto($tenant, $presets, $hub);
        $this->seedTiptapHowto($tenant, $presets, $hub);
    }

    /** @param array<string, LayoutPreset> $presets */
    protected function seedCustomBlocksHowto(Tenant $tenant, array $presets, Content $parent): void
    {
        Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'parent_id' => $parent->id,
            'title' => 'HowTo: Custom blocks',
            'path' => '/howto/custom-blocks',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subWeek(),
            'sort' => 41,
            'layout_preset_ids' => [$presets['narrow']->id],
            'meta' => ['seo_description' => 'Create and register your own builder block in filament-cms.'],
            'payload' => ['hero' => ['subtitle' => 'One class, two views, one config line. The hint box below is this demo\'s own custom block — built exactly this way.']],
            'blocks' => [$this->section([
                ['type' => 'hint', 'data' => ['active' => true, 'tone' => 'success', 'title' => 'Living proof', 'content' => '<p>This colored box is the <code>hint</code> block — the demo\'s own custom block. Source: <code>workbench/app/Support/Content/Blocks/hint/</code>. Open a page in the panel and add one via "Block hinzufügen".</p>']],
                $this->textChild('1 · The block class', '<p>A block is a class implementing <code>BuilderBlock</code> — usually via <code>BaseBuilderBlock</code>, which contributes the option fields (active, layout preset, anchor, heading), the rich editor with HTML tab, and upload paths. <code>key()</code> names the type; <code>make()</code> returns the Filament form definition:</p>'.$this->code('php', <<<'PHP'
                    class HintBlock extends BaseBuilderBlock
                    {
                        public function key(): string { return 'hint'; }

                        public function make(?Tenant $tenant): Block
                        {
                            return Block::make('hint')
                                ->icon(Heroicon::OutlinedLightBulb)
                                ->label('Hinweis')
                                ->title('title', placeholder: 'Titel', suffix: 'Hinweis') // inline header input
                                ->preview('blocks::hint.preview')                        // panel preview card
                                ->schema([
                                    ...static::optionHiddenFields(),  // active/title/preset/anchor/heading
                                    Select::make('tone')->options([
                                        'info' => 'Info', 'success' => 'Erfolg', 'warning' => 'Warnung',
                                    ])->default('info'),
                                    static::richEditorWithSource(),   // rich text + HTML source tab
                                ]);
                        }
                    }
                    PHP)),
                $this->textChild('2 · Two views', '<p>The frontend renders <code>&lt;x-block::hint&gt;</code> (anonymous component <code>hint/hint.blade.php</code>); the panel preview card renders <code>blocks::hint.preview</code>. Both receive the block\'s <code>data</code>:</p>'.$this->code('php', <<<'PHP'
                    {{-- resources/blocks/hint/hint.blade.php --}}
                    @php
                        $tone = $data['tone'] ?? 'info';
                        $renderedContent = RichText::render(data_get($data, 'content'));
                    @endphp

                    <div {{ $attributes->class(['hint', "hint-{$tone}"]) }}>
                        @if (filled($data['title'] ?? null))
                            <p class="hint-title">{{ $data['title'] }}</p>
                        @endif
                        <div class="richtext">{!! $renderedContent !!}</div>
                    </div>
                    PHP)),
                $this->textChild('3 · Register the view paths', '<p>Add your block directory to the same view namespaces the package uses (a service provider\'s <code>boot()</code>):</p>'.$this->code('php', <<<'PHP'
                    $blocks = resource_path('blocks'); // or wherever your blocks live

                    $this->loadViewsFrom($blocks, 'blocks');          // blocks::hint.preview
                    Blade::anonymousComponentPath($blocks, 'block');  // <x-block::hint>
                    PHP)),
                $this->textChild('4 · Register the block', '<p>Register the class via <code>Cms::registerBlocks()</code> (a service provider\'s <code>register()</code>) — the registry, all block pickers and the section allowlists pick it up:</p>'.$this->code('php', <<<'PHP'
                    Cms::registerBlocks([
                        ...Cms::defaultBlocks(),                            // section/media/text/listing
                        App\Support\Content\Blocks\hint\HintBlock::class,   // yours
                    ]);

                    // optional: restrict what editors may add, per site_key
                    Cms::allowSectionChildren('landing', ['text', 'media', 'hint']);
                    PHP)),
                ['type' => 'hint', 'data' => ['active' => true, 'tone' => 'info', 'title' => 'Where do blocks belong?', 'content' => '<p>Blocks that are useful for <strong>every</strong> project belong in the package (<code>src/Support/Content/Blocks</code>) so all sites receive them via <code>composer update</code>. Project-specific blocks stay in the app.</p>']],
            ])],
        ]);
    }

    /** @param array<string, LayoutPreset> $presets */
    protected function seedTiptapHowto(Tenant $tenant, array $presets, Content $parent): void
    {
        Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'parent_id' => $parent->id,
            'title' => 'HowTo: TipTap extensions',
            'path' => '/howto/tiptap-extensions',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subWeek(),
            'sort' => 42,
            'layout_preset_ids' => [$presets['narrow']->id],
            'meta' => ['seo_description' => 'Why filament-cms ships TipTap extensions and how to add your own.'],
            'payload' => ['hero' => ['subtitle' => 'The rich editor stores TipTap JSON, not HTML — extensions teach it which markup to keep and how to render it.']],
            'blocks' => [$this->section([
                $this->textChild('What they are & why they exist', '<p>Filament\'s RichEditor is <a href="https://tiptap.dev">TipTap</a>. It parses HTML into a JSON document and <strong>drops everything it has no extension for</strong> — a plain <code>&lt;div class="grid"&gt;</code> would silently disappear on the next edit. An extension therefore always has two halves:</p><ul><li><strong>JS</strong> (editor): parse + keep the markup while editing,</li><li><strong>PHP</strong> (server): render the stored JSON node back to HTML on the website.</li></ul><p>The package ships three: <code>HtmlDiv</code> (node) and <code>HtmlSpan</code> (mark) preserve class-carrying div/span markup — that\'s what makes the blocks\' <strong>HTML source tab</strong> round-trip-safe — and <code>link-attributes</code> teaches the built-in link mark the picker\'s extra attributes (title, wire:navigate). All are wired via the default panel plugins.</p>'.$this->code('php', <<<'PHP'
                    // The PHP half (rendering stored JSON → HTML), src/Tiptap/Nodes/HtmlDiv.php:
                    class HtmlDiv extends \Tiptap\Core\Node
                    {
                        public static $name = 'htmlDiv';

                        public function renderHTML($node, $HTMLAttributes = []): array
                        {
                            return ['div', $HTMLAttributes, 0];
                        }
                        // + parseHTML() / addAttributes() for the class attribute
                    }
                    PHP)),
                $this->textChild('The JS half', '<p>The editor-side extension lives in <code>resources/js/tiptap-extensions/</code> and uses <code>@tiptap/core</code>:</p>'.$this->code('php', <<<'PHP'
                    // resources/js/tiptap-extensions/html-span.js
                    import { Mark, mergeAttributes } from '@tiptap/core'

                    export default Mark.create({
                        name: 'htmlSpan',
                        addAttributes() {
                            return { class: { default: null } }
                        },
                        parseHTML() { return [{ tag: 'span' }] },
                        renderHTML({ HTMLAttributes }) {
                            return ['span', mergeAttributes(HTMLAttributes), 0]
                        },
                    })
                    PHP)),
                $this->textChild('Build & register the JS', '<p>Extensions are pre-built with esbuild (<code>npm run build</code> → <code>resources/dist</code>) and registered as lazy Filament assets; <code>php artisan filament:assets</code> publishes them into the app:</p>'.$this->code('php', <<<'PHP'
                    // CmsServiceProvider::boot()
                    FilamentAsset::register([
                        Js::make('tiptap-html-div', __DIR__.'/../resources/dist/tiptap-extensions/html-div.js')
                            ->loadedOnRequest(),   // loaded only when an editor needs it
                    ], package: 'mmoollllee/filament-cms');
                    PHP)),
                $this->textChild('Expose both halves as a plugin', '<p>A <code>RichContentPlugin</code> ties the pair together — PHP extensions for rendering, JS sources for the editor — and is added in <code>configureRichEditor()</code>:</p>'.$this->code('php', <<<'PHP'
                    class HtmlPreservePlugin implements RichContentPlugin
                    {
                        public function getTipTapPhpExtensions(): array
                        {
                            return [app(HtmlDiv::class), app(HtmlSpan::class)];
                        }

                        public function getTipTapJsExtensions(): array
                        {
                            return [
                                FilamentAsset::getScriptSrc('tiptap-html-div', 'mmoollllee/filament-cms'),
                                FilamentAsset::getScriptSrc('tiptap-html-span', 'mmoollllee/filament-cms'),
                            ];
                        }

                        public function getEditorTools(): array { return []; }
                        public function getEditorActions(): array { return []; }
                    }
                    PHP)),
                $this->textChild('Adding your own', '<p>The same recipe for any custom markup (e.g. a <code>&lt;mark&gt;</code> highlight, an icon shortcode node):</p><ol><li>PHP extension under <code>app/Tiptap/…</code> (extends <code>Tiptap\Core\Node</code> or <code>Mark</code>),</li><li>JS extension + esbuild entry, registered via <code>FilamentAsset</code> in a provider,</li><li>a <code>RichContentPlugin</code> exposing both,</li><li>append the plugin in your PanelProvider\'s <code>configureRichEditor()</code> override,</li><li>make sure the frontend renderer knows the PHP extension — plugins passed to the editor are picked up by <code>RichText::render()</code> automatically.</li></ol>'.$this->code('php', <<<'PHP'
                    // app/Providers/Filament/PanelProvider.php
                    protected function configureRichEditor(): void
                    {
                        parent::configureRichEditor();

                        RichEditor::configureUsing(function (RichEditor $component): void {
                            $component->plugins([MyHighlightPlugin::make()]);
                        });
                    }
                    PHP)),
            ])],
        ]);
    }

    /**
     * The self-documenting demo texts — the former docs/DEMO.md as flat text children
     * of the home page's "Documentation" section: setup, tenants, logins, page map,
     * feature matrix and where to look in code. Tabular content is HTML <table>
     * markup (the RichEditor renderer keeps table nodes; the stylesheet styles them).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function docTexts(): array
    {
        $tenants = <<<'HTML'
            <table>
                <tr><th>Tenant</th><th>site_key</th><th>Host</th><th>Role in the demo</th></tr>
                <tr><td>filament-cms</td><td><code>marketing</code></td><td>127.0.0.1:8000</td><td>The docs site. Branding <strong>source</strong> (lowest id).</td></tr>
                <tr><td>Acme GmbH</td><td><code>acme</code></td><td>localhost:8000</td><td>Inherits branding (null fields) AND is the <strong>onepager demo</strong>: its frontend is composed of <code>default.section</code> contents.</td></tr>
            </table>
            HTML;

        $logins = <<<'HTML'
            <table>
                <tr><th>Email</th><th>Role</th><th>Tenant</th><th>Demonstrates</th></tr>
                <tr><td><code>admin@example.test</code></td><td>Superadmin</td><td>both</td><td>Sees/manages every tenant</td></tr>
                <tr><td><code>admin-a@example.test</code></td><td>Admin</td><td>filament-cms</td><td>Tenant admin (can manage users)</td></tr>
                <tr><td><code>editor-a@example.test</code></td><td>Editor</td><td>filament-cms</td><td>Editor (no user management)</td></tr>
                <tr><td><code>admin-b@example.test</code></td><td>Admin</td><td>Acme GmbH</td><td>Admin of the second tenant</td></tr>
                <tr><td><code>multi@example.test</code></td><td>Editor (filament-cms) / Admin (Acme)</td><td>both</td><td>The tenant switcher shows two sites</td></tr>
            </table>
            HTML;

        $pages = <<<'HTML'
            <table>
                <tr><th>Path</th><th>Shows</th></tr>
                <tr><td><code>/</code></td><td>Marketing home + this self-documentation (tenants, logins, feature matrix)</td></tr>
                <tr><td><code>/features</code></td><td>Each feature with an intro + code snippet</td></tr>
                <tr><td><code>/features?preview=1</code></td><td>Draft preview mode — the features page carries a pending draft (log in first)</td></tr>
                <tr><td><code>/panel</code> → Dashboard</td><td>„Letzte Änderungen" widget + the <strong>Revisionen</strong> action on any edited page (the home page ships with history)</td></tr>
                <tr><td><code>/blocks</code></td><td>Live block showcase — every block rendered next to its code</td></tr>
                <tr><td><code>/customize</code></td><td>Every extension point (the customization guide as seeded content)</td></tr>
                <tr><td><code>/howto</code></td><td>Step-by-step guides: custom blocks (live HintBlock proof) + TipTap extensions</td></tr>
                <tr><td><code>/imprint</code>, <code>/privacy</code></td><td>Legal pages (noindex)</td></tr>
                <tr><td><code>/draft</code>, <code>/scheduled</code>, <code>/members</code></td><td>Draft / scheduled / members-only → 404 for guests</td></tr>
                <tr><td><code>localhost:8000/</code> (+ <code>/leistungen</code>, <code>/kontakt</code>)</td><td>Tenant B as an <strong>onepager</strong> — every path renders the same shell of stacked sections</td></tr>
            </table>
            HTML;

        $matrix = <<<'HTML'
            <table>
                <tr><th>Feature</th><th>Where</th></tr>
                <tr><td>Multi-tenancy + domain routing</td><td>Same paths, different content on <code>127.0.0.1</code> vs. <code>localhost</code></td></tr>
                <tr><td>Branding inheritance</td><td>Acme GmbH (<code>localhost</code>) shows "filament-cms" branding it never set</td></tr>
                <tr><td>Builder blocks</td><td><code>/blocks</code> renders every block type live</td></tr>
                <tr><td>Custom blocks (app-side)</td><td>The <code>hint</code> block on <code>/howto/custom-blocks</code> is registered by the workbench, not the package</td></tr>
                <tr><td>Rich-editor custom blocks</td><td>Button group + navigation cards on <code>/</code> and <code>/blocks</code></td></tr>
                <tr><td>Layout presets</td><td>Section grids, headers, listing wrapper — all preset-driven</td></tr>
                <tr><td>Onepager &amp; sections</td><td>Tenant B (<code>localhost</code>): root <code>default.section</code> contents compose one page, each with its own path</td></tr>
                <tr><td>Publishing states</td><td><code>/draft</code>, <code>/scheduled</code>, <code>/members</code> → 404 for guests</td></tr>
                <tr><td>Drafts &amp; Vorschau</td><td><code>/features</code> carries a pending draft — log in, open <code>/features?preview=1</code>, leave via the floating badge</td></tr>
                <tr><td>Versionierung &amp; Restore</td><td>The home page was seeded, then edited twice: its edit page shows <strong>Revisionen</strong> (diff + restore), the dashboard lists the changes. Drafts never appear in the history.</td></tr>
                <tr><td>SEO</td><td><code>meta.*</code> per page; noindex on legal pages; tenant default SEO</td></tr>
                <tr><td>Fragments / menus / spam quiz / roles</td><td>Seeded; visible in the panel and (menus) in the header/footer</td></tr>
            </table>
            HTML;

        $codeMap = <<<'HTML'
            <ul>
                <li><strong>Seeder (the tour):</strong> <code>workbench/database/seeders/DatabaseSeeder.php</code></li>
                <li><strong>Content model + scopes:</strong> <code>workbench/app/Models/Content.php</code></li>
                <li><strong>Blueprints (content types):</strong> <code>src/Sites/Default/*</code>, <code>workbench/app/Sites/Marketing/*</code></li>
                <li><strong>Builder blocks:</strong> <code>src/Support/Content/Blocks/*</code> (PHP) + <code>resources/blocks/*</code> (views)</li>
                <li><strong>Frontend rendering:</strong> <code>src/Http/Controllers/Frontend/*</code>, <code>resources/views/content/page.blade.php</code></li>
                <li><strong>Tests that pin the demo:</strong> <code>tests/Feature/DemoSeederTest.php</code>, <code>DemoSeederRenderTest.php</code></li>
            </ul>
            HTML;

        return [
            $this->textChild('Run it locally',
                '<p>Install, publish assets, migrate + seed, serve:</p>'
                .$this->code('bash', "composer install                      # needs auth.json for packages.filamentphp.com\nvendor/bin/testbench filament:assets  # publish Filament CSS/JS (once)\nvendor/bin/testbench migrate:fresh    # migrate + auto-seed the demo\ncomposer serve                        # http://127.0.0.1:8000")
                .'<p>Panel: <code>/panel</code>. All demo users share the password <code>password</code> — prefilled on the login page in local env.</p>'),
            $this->textChild('Tenants', '<p>Domain-based multi-tenancy — the tenant is resolved from the request host:</p>'.$tenants),
            $this->textChild('Users & roles', '<p>All demo logins (password <code>password</code>):</p>'.$logins),
            $this->textChild('Demo pages', '<p>What the frontend shows:</p>'.$pages),
            $this->textChild('Feature matrix', '<p>What the demo exercises — also behind the scenes:</p>'.$matrix),
            $this->textChild('Where to look in code', '<p>The landmarks in the repo:</p>'.$codeMap),
        ];
    }

    protected function seedPublishingDemos(Tenant $tenant): void
    {
        // Draft / scheduled / members-only → 404 for guests; visible in the panel.
        Content::create(['tenant_id' => $tenant->id, 'content_type' => 'default.page', 'title' => 'Draft', 'path' => '/draft', 'visibility' => ContentVisibility::Public, 'publish_from' => null]);
        Content::create(['tenant_id' => $tenant->id, 'content_type' => 'default.page', 'title' => 'Scheduled', 'path' => '/scheduled', 'visibility' => ContentVisibility::Public, 'publish_from' => now()->addWeek()]);
        Content::create(['tenant_id' => $tenant->id, 'content_type' => 'default.page', 'title' => 'Members', 'path' => '/members', 'visibility' => ContentVisibility::Members, 'publish_from' => now()->subWeek()]);
    }

    protected function seedLegal(Tenant $tenant): void
    {
        foreach ([['Imprint', '/imprint'], ['Privacy', '/privacy']] as [$title, $path]) {
            Content::create([
                'tenant_id' => $tenant->id,
                'content_type' => 'default.page',
                'title' => $title,
                'path' => $path,
                'visibility' => ContentVisibility::Public,
                'publish_from' => now()->subWeek(),
                'meta' => ['noindex' => true], // demonstrates the noindex SEO toggle
                'blocks' => [['type' => 'section', 'data' => ['active' => true, 'content' => "<p>{$title} of the filament-cms demo.</p>"]]],
            ]);
        }
    }

    // ── Tenant B: onepager from sections + branding inheritance ───────────────

    /**
     * The second tenant is BOTH multi-tenancy proof (null branding → inherits from
     * tenant A) AND the live demo of the sections/onepager feature (as used by real
     * client sites): its frontend is composed of root `default.section` contents —
     * each with its own path, every path rendering the same onepager shell
     * (workbench/resources/views/frontend/onepager.blade.php).
     *
     * @param  array<string, User>  $users
     */
    protected function seedSecondTenant(array $users): Tenant
    {
        // Null branding fields → name/claim/color inherited from filament-cms (tenant A).
        $tenant = Tenant::factory()->create([
            'name' => 'Acme GmbH',
            'site_key' => 'acme',
            'primary_domain' => 'localhost',
            'brand_name' => null,
            'brand_claim' => null,
            'primary_color' => null,
        ]);

        $tenant->users()->attach($users['superadmin'], ['role' => TenantUserRole::Admin->value]);
        $tenant->users()->attach($users['adminB'], ['role' => TenantUserRole::Admin->value]);
        $tenant->users()->attach($users['multiTenant'], ['role' => TenantUserRole::Admin->value]);

        $sections = [
            ['/', 'Willkommen', 0, [$this->textChild(null,
                '<p>Diese Website läuft auf <code>localhost</code>, die Docs auf <code>127.0.0.1</code> — getrennte Inhalte, gemeinsame Engine, <strong>geerbtes Branding</strong> (Name, Claim, Farbe kommen vom Branding-Tenant).</p>'
                .'<p>Und sie ist ein <strong>Onepager</strong>: jede Sektion unten ist ein eigener Inhalt vom Typ <code>default.section</code> mit eigenem Pfad (<code>/leistungen</code>, <code>/kontakt</code>) — alle Pfade rendern dieselbe Seite.</p>')]],
            ['/leistungen', 'Leistungen', 10, [$this->textChild(null,
                '<p>Sektionen sind normale Inhalte: Block-Builder, Layout-Presets, Veröffentlichung — alles wie bei Seiten. Im Panel werden sie über den <strong>Seiten-Typ</strong> "Sektion" angelegt — freigeschaltet, weil die <code>Acme</code>-Site-Extension das <code>default.section</code>-Blueprint mit <code>offeredInTypeSelect</code> überschreibt.</p>'
                .'<ul><li>Eigener Pfad pro Sektion → teilbare Links &amp; Sitemap-Einträge</li><li>Reihenfolge über das Sortierfeld</li><li>Teaser-Modus pro Sektion (<code>supportsTeasers</code>)</li></ul>')]],
            ['/kontakt', 'Kontakt', 20, [$this->textChild(null,
                '<p>Kontaktdaten kommen aus den Tenant-Einstellungen — hier per Vererbung vom Branding-Tenant, spam-geschützt gerendert: [contact_email_link]</p>')]],
        ];

        foreach ($sections as [$path, $title, $sort, $children]) {
            Content::create([
                'tenant_id' => $tenant->id,
                'content_type' => 'default.section',
                'title' => $title,
                'path' => $path,
                'sort' => $sort,
                'visibility' => ContentVisibility::Public,
                'publish_from' => now()->subWeek(),
                // No block-level title: the content view already renders the
                // section's content title as its heading.
                'blocks' => [$this->section($children)],
            ]);
        }

        $this->seedMenu($tenant, 'header', 'Main menu', [
            ['title' => 'Start', 'url' => '/'],
            ['title' => 'Leistungen', 'url' => '/leistungen'],
            ['title' => 'Kontakt', 'url' => '/kontakt'],
        ]);

        return $tenant;
    }

    // ── Content helpers ───────────────────────────────────────────────────────
    // A text block carries its own header (title/eyebrow via the option fields),
    // so most prose lives as FLAT text children inside ONE section per page —
    // no section-per-paragraph nesting, flatter editing in the panel.

    /** A section wrapping child blocks; pass extra data (title, presets, …) as needed. */
    protected function section(array $children, array $data = []): array
    {
        return ['type' => 'section', 'data' => $data + ['active' => true, 'blocks' => $children]];
    }

    /** A text child with its own header (h2 title) and HTML content. */
    protected function textChild(?string $title, string $html): array
    {
        return ['type' => 'text', 'data' => [
            'active' => true,
            'title' => $title,
            'heading' => 'h2',
            'content' => $html,
        ]];
    }

    /** A pre/code HTML snippet for embedding in text content (code is escaped). */
    protected function code(string $language, string $code): string
    {
        return '<pre><code class="language-'.$language.'">'.e($code).'</code></pre>';
    }

    /** A TipTap doc embedding the buttonGroup rich-editor custom block. */
    protected function buttons(string $alignment, array $buttons): array
    {
        $buttons = array_map(fn (array $b): array => $b + ['size' => 'md', 'icon' => null, 'icon_position' => 'after', 'wire_navigate' => true, 'rel' => null], $buttons);

        return ['type' => 'doc', 'content' => [
            ['type' => 'customBlock', 'attrs' => ['id' => 'buttonGroup', 'config' => ['alignment' => $alignment, 'buttons' => $buttons]]],
        ]];
    }

    /** A TipTap doc embedding the navigationCardGroup rich-editor custom block. */
    protected function navCards(array $cards): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'customBlock', 'attrs' => ['id' => 'navigationCardGroup', 'config' => ['cards' => $cards]]],
        ]];
    }

    /**
     * Create a tenant-scoped menu, place it in a location, add items.
     *
     * @param  array<int, array{title: string, url: string}>  $items
     */
    protected function seedMenu(Tenant $tenant, string $location, string $name, array $items): void
    {
        $menu = Menu::create(['name' => $name, 'tenant_id' => $tenant->id, 'is_visible' => true]);
        $menu->locations()->create(['location' => $location, 'tenant_id' => $tenant->id]);

        foreach ($items as $order => $item) {
            $menu->menuItems()->create(['title' => $item['title'], 'url' => $item['url'], 'order' => $order]);
        }
    }
}
