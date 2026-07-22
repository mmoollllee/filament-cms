# filament-cms — Customization Guide

`mmoollllee/filament-cms` is the shared engine for multi-tenant marketing sites. It ships
the routing/templating engine, the content-type (blueprint) + site-extension system, the
block builder, the RichEditor stack, field kits, shortcodes, redirects/404 handling and
the Filament admin panel. The full feature list with examples: [FEATURES.md](FEATURES.md).

**Design principle:** the package is the *updatable baseline*. Everything project-specific —
content types, models, layouts, templates, extra fields/blocks/shortcodes — stays in the
consuming app and is layered on top via config, contracts, traits and extension hooks.
Improvements made while building site N reach sites 1…N-1 with `composer update`.

The provider `Mmoollllee\Cms\CmsServiceProvider` is **auto-discovered**, so requiring the
package wires the engine singletons (incl. the block registry), views, policies, cache
observers, the `/_resolve404` route and the scheduled 404-pruning.

---

## 1. Engine wiring — the `Cms::` registry + `config/cms.php`

Everything structural is registered **in code** from a service provider
(`cms:install` scaffolds `App\Providers\CmsServiceProvider` for exactly this),
following the Cashier/Sanctum `use*Model()` convention. Panel options (path,
vite theme, page discovery) are NOT registered here — set them fluently on the
Panel in your `PanelProvider`, Filament-style (see §9).

```php
use Mmoollllee\Cms\Cms;

public function register(): void
{
    // REQUIRED: the app models the engine resolves at runtime (see §2).
    Cms::useContentModel(\App\Models\Content::class);
    Cms::useTenantModel(\App\Models\Tenant::class);
    Cms::useFragmentModel(\App\Models\Fragment::class); // optional — enables the FragmentResource
    // Cms::useUserModel(…);                            // optional — default: auth.providers.users.model

    // Base resource per-type content resources extend (see §3).
    // Cms::useResourceBase(TenantScopedContentResource::class);

    // Site-extension discovery (see §3). Default: app_path('Sites') → App\Sites.
    // Cms::discoverSitesIn(app_path('Sites'), 'App\\Sites');

    // Builder blocks + allowlists (see §8). Default: the four core blocks.
    // Cms::registerBlocks([...Cms::defaultBlocks(), MyBlock::class]);
    // Cms::allowSectionChildren('my-site-key', ['text', 'media', 'listing']);
    // Cms::allowRootBlocks('my-site-key', ['section', 'hero']);

    // Catch-all resource + opt-in "Titelbereich" on its form (see §9).
    // Cms::useContentResource(CatchAllContentResource::class);
    // Cms::enableContentPageHeader();

    // Menu-builder locations — panel plugin + cache invalidation share this list.
    // Cms::useMenuLocations(['header' => 'Hauptmenü', 'footer' => 'Sekundär-Navigation']);

    // Frontend fallback views: footer claim (see §5 for merge-tag labels).
    // Cms::useFooterTagline('…');
}
```

`config/cms.php` keeps only environment-driven settings — publishing it is
optional (`php artisan vendor:publish --tag=cms-config`), every value is
env-backed:

```php
return [
    // Tenant whose branding satellites inherit (null = lowest-id tenant).
    'default_branding_tenant_id' => env('CMS_BRANDING_TENANT_ID'),

    // Local-env login prefill (null = never prefill).
    'dev_login' => [
        'email' => env('CMS_DEV_LOGIN_EMAIL'),
        'password' => env('CMS_DEV_LOGIN_PASSWORD'),
    ],

    // Redirect + 404 subsystem — see config/cms.php for the full annotated set.
    'redirects' => [ /* enabled, auto_redirect, thresholds, statuses, pruning, … */ ],
];
```

(The Seiten-Typ choice is a BLUEPRINT flag, not wiring — see §3.)

---

## 2. Models & contracts

The engine never references your concrete models — it types against
`Mmoollllee\Cms\Contracts\{Content, Tenant, User, Fragment}` and resolves classes via
the `Cms::use*Model()` registrations (getters: `Cms::contentModel()` etc.). Your app
owns its models, columns and relations.

> **Shortcut:** `php artisan cms:install` scaffolds all of this (models on the traits
> below, `CmsServiceProvider` with the models registered, panel provider, frontend
> routes) — see the README quick start. The rest of this section explains what the
> scaffolding gives you.

**Everything the engine expects beyond the interface methods ships as package traits** —
use them instead of copying code; improvements reach every site via `composer update`:

```php
use Mmoollllee\Cms\Concerns\Content\{AssignsCurrentTenant, ConvertsUploadedVideos,
    GeneratesPathAndSlug, HasPublishingStatus, ResolvesLayoutPresets};

class Content extends Model implements \Mmoollllee\Cms\Contracts\Content, MenuPanelable
{
    use AssignsCurrentTenant;     // fills tenant_id from the resolved host tenant
    use ConvertsUploadedVideos;   // dispatches the video re-encode job on save
    use GeneratesPathAndSlug;     // path/slug generation incl. non-routable types
    use HasPublishingStatus;      // status(), resolved_status, scopePublished/VisibleTo/OfType
    use ResolvesLayoutPresets;    // resolvedLayoutPreset() for the frontend

    // yours: casts, relations (tenant/parent/children), payload accessors, …
}

use Mmoollllee\Cms\Concerns\Tenant\{HasContents, HasSpamQuestions, HasTenantUsers,
    InheritsBranding};

class Tenant extends Model implements \Mmoollllee\Cms\Contracts\Tenant
{
    use HasContents;              // contents() + visibleContents()
    use HasSpamQuestions;         // tenant-configured spam-protection questions
    use HasTenantUsers;           // users()/creator(), hasUser(), isVisibleTo()
    use InheritsBranding;         // resolved*() branding cascade + SEO defaults

    // yours: $fillable, casts (visibility, social_links, imprint/privacy_data), …
}

use Mmoollllee\Cms\Concerns\User\BelongsToTenants;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants,
    \Mmoollllee\Cms\Contracts\User
{
    use BelongsToTenants;         // tenants()/roles + host-aware Filament tenancy methods
}
```

What the tenant/user traits cover in detail:

- **`InheritsBranding`** — the full branding cascade: every `resolved*()` method
  (brand name/claim, logos, favicon, primary color, SEO title/description, OG image,
  social links) falls back from the tenant's own value to the branding tenant
  (`cms.default_branding_tenant_id`, default: lowest-id tenant), then to a default.
  Also `frontendTitleFor()`, `displayName()`, `isBrandingSource()` and the
  `DEFAULT_PRIMARY_COLOR` constant.
- **`HasTenantUsers`** — `users()`/`creator()` relations (resolved via
  `Cms::userModel()`), `hasUser()` and the `isVisibleTo()` visibility rules.
- **`HasContents`** — `contents()` + `visibleContents()`. Override
  `visibleContents()` in the model for project-specific filtering (the class
  method wins over the trait).
- **`BelongsToTenants`** (User) — `tenants()` relation, `isSuperadmin()`,
  `tenantRole()`, and the host-aware Filament tenancy methods (`canAccessPanel()`,
  `getTenants()`, `getDefaultTenant()`). Keep `is_superadmin` OUT of `$fillable` —
  it is a global authorization kill-switch; set it explicitly (factory state /
  seeder), never from request data.
- **Fragment** (optional): `ResolvesFragmentWithCascade` trait + `Contracts\Fragment`.

Column expectations stay app-side (see the workbench models / the `cms:install`
stubs for the reference `$fillable` + casts): Content casts `blocks`/`payload`/
`meta`/`layout_preset_ids` (array), `publish_from`/`publish_until` (datetime),
`visibility` (`ContentVisibility`); Tenant casts `visibility` (`TenantVisibility`)
and `social_links`/`imprint_data`/`privacy_data` (array).

> Blade icons: `IconOptions` and the `[logo]` shortcode render app-registered icon sets
> with prefixes `icon` and `image` (config/blade-icons.php). Register those sets (or
> avoid the features) — an unregistered set throws `SvgNotFound` at render time.

---

## 3. Site extensions, blueprints & content resources

A **site extension** (`<sites.path>/<Name>/SiteExtension.php` implementing
`Contracts\SiteExtension`) is auto-discovered and groups content types for a tenant
`site_key`. The `default` extension (shipped by the package) always loads; the tenant's
`site_key` extension loads on top.

```php
namespace App\Sites\MySite;

class SiteExtension implements \Mmoollllee\Cms\Contracts\SiteExtension
{
    use DiscoversSiteBlueprints;   // finds <dir>/<Type>/Blueprint.php
    use DiscoversSiteResources;    // finds <dir>/<Type>/Resource.php

    public function siteKey(): string { return 'my-site'; }
}
```

A **content type** is a `Blueprint` (extend `ConfiguredContentBlueprint`, set
`$key`, `$label`, `$defaultTemplate`, `$urlPathPrefix`, `$hasBuilder`, `$isRoutable`,
`$allowedParentTypes`, …) plus an optional Filament `Resource` extending
`Cms::resourceBase()`. Discovery only registers resources extending that base.

**Pages are 3-liners** — extend the package base pages and inherit the builder
clipboard/drag&drop wiring, payload-preserving saves, child-management actions,
parent-scoped listings and the wide layout:

```php
class ListPage extends \Mmoollllee\Cms\Filament\Resources\Contents\Pages\ContentListPage
{
    protected static string $resource = Resource::class;
}
// same for CreatePage extends ContentCreatePage, EditPage extends ContentEditPage
```

**Non-routable types (`$isRoutable = false`)**: no URL — the form swaps "Pfad" for a
tenant-unique slug, `path` stays null. For embedded-but-referenceable content
(FAQ items, team members, services listed by a listing block).

**Hierarchy (`$allowedParentTypes`)**: declares which types a record may nest under —
it drives the parent select, breadcrumbs, the "… verwalten" child actions and
parent-scoped listings. `default.page` allows `default.page` out of the box. For
routable types WITHOUT a `urlPathPrefix` the path follows the parent
(parent path + own segment, subtree moves on rename); a `urlPathPrefix` keeps
type-based paths instead.

**Seiten-Typ select (`$offeredInTypeSelect`)**: the catch-all form shows its type
select only when the site has more than one offered routable type. `default.section`
ships with the flag OFF — an onepager site enables it by **overriding the blueprint
per site** (same key, subclass; the site extension's blueprint replaces the default
one for that site):

```php
// app/Sites/Jobs/Section/Blueprint.php — discovered like any site blueprint
class Blueprint extends \Mmoollllee\Cms\Sites\Default\Section\Blueprint
{
    protected bool $offeredInTypeSelect = true;
}
```

---

## 4. Field kits

Fluent field-set builders (`Mmoollllee\Cms\Fields\FieldKit`):

```php
SeoFields::make()->toArray();                       // all fields
SeoFields::make()->without('og_image')->toArray();  // drop some
SeoFields::make()->only('title', 'description');    // keep some
PublishingFields::make()->extend([$extra])->prepend([$first])->toArray();
```

Shipped kits: **`SeoFields`**, **`PublishingFields`**
(`->defaultVisibilityUsing(fn (Get $get) => …)`), **`PageHeaderFields`**
(`->uploadDirectory($dir)`). Wiring is per-resource: compose, reorder, extend or replace.

---

## 5. Shortcodes

`Support\Shortcodes` provides the `[token]` mechanism + generic tenant shortcodes
(`[logo]`, `[company_name]`, `[contact_email]`, …). Render with `Shortcodes::render($html)`
— `RichText::render()` does it for you.

```php
// Replace the RichEditor merge-tag picker labels (e.g. CmsServiceProvider)
Shortcodes::useMergeTags(['company_name' => 'Firmenname', /* … */]);

// project shortcodes, reset-safe (e.g. CmsServiceProvider::boot())
Shortcodes::extendDefaultsUsing(function (): void {
    Shortcodes::register('my_tag', fn (array $attrs): string => '<span>…</span>');
    Shortcodes::registerMergeTagValue('my_tag', fn () => '…');
});
```

The spam-protected `[contact_email_link]`/`[contact_phone_link]` ship in the package
(`ContactLinkShortcodes`, registered automatically — laravel-spamprotect is a
package dependency).

---

## 6. Page header (opt-in)

The base content resource's `pageHeaderSection()` hook returns `null` — a resource has
**no** page header unless it opts in:

```php
class Resource extends TenantScopedContentResource
{
    use \Mmoollllee\Cms\Filament\Resources\Concerns\RendersPageHeader;
}
```

(Or override `pageHeaderSection()` directly. The catch-all resource opts in via
`Cms::enableContentPageHeader()`.)

---

## 7. RichEditor stack

The panel applies the standard editor config globally
(`BasePanelProvider::configureRichEditor()`): the awcodes richer-editor plugins, the
package `LinkPickerPlugin` (internal-path autocomplete modal), `HtmlPreservePlugin`
(div/span survival + the HTML source tab), the custom blocks
(`ButtonGroupBlock`, `NavigationCardGroupBlock`) and the shared toolbar. Override
`configureRichEditor()` in your PanelProvider subclass to change any of it.

**Adding a TipTap extension** (preserve/render more custom markup): implement the PHP
side (`Tiptap\Core\{Node,Mark}` subclass — see `src/Tiptap/`), the JS side
(`resources/js/tiptap-extensions/*.js` built with `npm run build`, registered via
`FilamentAsset` + `loadedOnRequest()`), and expose both through a `RichContentPlugin`.
The demo's "TipTap extensions" HowTo shows a complete walk-through.

`Support\Content\RichText::render($content)` renders stored rich text (renderer +
shortcodes + spam-protected contact links) — use it in every frontend view.

---

## 8. Blocks

**Registration is code** — the package binds the `BuilderBlockRegistry` singleton from
`Cms::blocks()` (defaults to section/media/text/listing). Register the complete
ordered list (order = picker order) in a service provider; compose with
`Cms::defaultBlocks()` to keep the core set:

```php
// e.g. App\Providers\CmsServiceProvider::register()
Cms::registerBlocks([
    ...Cms::defaultBlocks(),
    \App\Support\Content\Blocks\hero\HeroBlock::class,   // yours
]);

// Optional per-site restrictions:
Cms::allowSectionChildren('my-site-key', ['text', 'media', 'listing']);
Cms::allowRootBlocks('my-site-key', ['section', 'hero']);
```

**A block class** implements `Support\Content\Blocks\Contracts\BuilderBlock` (usually via
`BaseBuilderBlock`, which contributes `richEditorWithSource()`, `optionHiddenFields()`,
`uploadDirectory()` and the shared item actions). Views: `blocks::{key}.preview` (panel
preview card) + `<x-block::{key}>` (frontend) — register an app view path or publish the
package views (`vendor:publish --tag=cms-blocks`) to override the core ones. Full
walk-through: the demo's "Custom blocks" HowTo.

**Builders are built by one factory** — `Filament\Forms\BlockBuilder::make($statePath,
$tenant, $blocks, previews: …, sortableGroup: …, extraItemActions: …)` configures every
builder (icons, add labels, the scope-aware "Block-Optionen" gear, "Block kopieren",
preview inline-editing). Improve it once, every builder everywhere updates.

---

## 9. Admin panel (`BasePanelProvider`)

Apps register a **thin subclass** (in `bootstrap/providers.php`):

```php
class PanelProvider extends \Mmoollllee\Cms\Filament\Providers\BasePanelProvider
{
    // Panel options fluently on the Panel, Filament-style: vite theme, path,
    // extra page discovery, plugins …
    protected function configurePanel(Panel $panel): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/theme.css')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages');
    }
}
```

Defaults the base already wires — override only to change:

| Hook | Default |
|---|---|
| `panelResources()` | catch-all content resource (`Cms::contentResource()`) + FragmentResource (when a fragment model is configured) + `coreResources()` + all site-extension resources |
| `coreResources()` | Redirects, 404-Log, LayoutPresets, Users |
| `panelPages()` | the package Dashboard |
| `tenantProfilePage()` | package page (branding/contact/SEO + spam questions when the Tenant uses `HasSpamQuestions`) |
| `configureRichEditor()` | the standard editor stack (§7) |
| `configurePanel(Panel)` | identity — your seam for `->path()`, `->viteTheme()`, discovery, plugins |

Also automatic: the `LoginResponse` → `TenantAwareLoginResponse` binding (host-aware
post-login redirect), tenant branding (name/logo/primary color), the menu-builder plugin
(locations from `Cms::menuLocations()`), the tenant middleware incl. persistent
`ResolveTenantFromHost`, and the local-env login prefill (`cms.dev_login`, env-backed).

> Tenant access in panel code: `Filament::getTenant()` is the panel-URL tenant,
> `app(CurrentTenant::class)->get()` the host-resolved one. In the panel they coincide
> (domain-keyed tenancy) — prefer `Filament::getTenant()` in resources/pages and
> `CurrentTenant` in engine/frontend code.

---

## 10. Frontend views & JS

The package ships brand-agnostic fallbacks (standalone + onepager shells, content/page,
branded 404/500, header partials, `<x-site.*>` components). Resolution order: app views
(`resources/views/…`) → package fallbacks; publish to customize:

```bash
php artisan vendor:publish --tag=cms-frontend        # shells, partials, errors
php artisan vendor:publish --tag=cms-site-components # <x-site.*> design components
php artisan vendor:publish --tag=cms-blocks          # block views
```

Site-specific overrides win over both: `{site_key}/errors/404.blade.php`,
`{site_key}.content.page`, ….

### Frontend JS runtime

The shells and the floating header bind against Alpine components the package ships as
plain ES modules (`resources/js/frontend/`) — **not** published copies: the app imports
them from the vendor dir (same pattern as the consent-control runtime), so fixes reach
every site via `composer update`:

- **`siteOnepager`** — section lazy-loading via `/_content` (each injected section
  dispatches a bubbling `cms:section-loaded` event for app hooks), scroll-synced
  URL/title/indicator, anchor + history handling. **Architecture only** — no visual
  behavior ships here.
- **`siteChildNavigation`** — breadcrumbs, local-section tracking + flyout state on
  standalone pages.

```js
// resources/js/app.js
import { registerCmsFrontend } from '../../vendor/mmoollllee/filament-cms/resources/js/frontend/index.js';

document.addEventListener('alpine:init', () => {
    registerCmsFrontend(window.Alpine);
});
```

#### Extension hooks — where brand behavior plugs in

Visual frontend behavior (scroll-hint pills, hero-logo fades, measured header
fitting, progress bars, …) is **app territory**. The core exposes three hooks and
calls them at guaranteed lifecycle points; apps layer mixins over the components via
override factories (their members are merged over the package component, so each
name wins wholesale):

- `updateViewportState()` — empty in core; called at init, after every lazy section
  injection, in the `goToSection()` rAF, every scroll frame and every resize frame.
- `showLogo()` — `true` in core; the floating header binds its logo opacity to it.
- `onResize()` — core runs only the rAF'd scroll-drift correction
  (`this.resizeFrame()`). Overrides that cache viewport geometry reset their caches
  here and **must** end with `this.resizeFrame()`.

```js
registerCmsFrontend(window.Alpine, {
    onepager: (el) => ({
        ...scrollHintsMixin(),                 // app-owned modules
        ...heroLogoMixin(),
        updateViewportState() {                // define collision-prone hooks ONCE
            this.updateHeroLogoVisibility();
            this.updateScrollHints();
        },
    }),
    childNavigation: () => ({ ...headerBarMixin() }),
});
```

Compose multiple mixins into ONE override object per component — the merge is a flat
member set, so `updateViewportState`/`onResize` must be defined exactly once (by the
composing module, not the mixins). The muench-tiefbau.de repo is the reference
implementation: `resources/js/site/{onepager,scroll-hints,hero-logo,header-bar,scroll-store}.js`
plus its `resources/views/frontend/onepager.blade.php` and
`resources/views/partials/` header copies.

View contract of the fallback onepager shell (`frontend/onepager.blade.php`):
sections carry `.onepager-section` +
`data-path/-loaded/-title/-label/-navigation[/-anchor]`, the root carries
`data-content-endpoint`. The fallback floating header is **self-contained** (inline
SVG icons, tenant logo via `resolvedMainLogoUrl()` — no app blade-icon sets needed)
and binds only core members plus `[data-role="header-indicator"]` /
`nav[data-role="header-breadcrumbs"]` / `.logo-link` / `.nav-menu-btn` as styling and
extension hooks. Everything beyond that (pills markup + `x-ref`s, measurer spans,
`x-init="initHeaderBar()"`, `$store.scroll` bindings) belongs to app view copies —
publish the starting points via `php artisan vendor:publish --tag=cms-frontend`.
`tests/Feature/FallbackShellRenderTest.php` pins this brand-agnostic contract.

`<x-site.favicon>` ships as a brand-agnostic fallback: it emits a single icon
link from `favicon_path` with branding inheritance (apps with full icon sets —
sizes, apple-touch, manifest — override it, guarded by
`tests/Feature/FaviconFallbackTest.php`).

#### SEO head — meta overrides & extension seams

`<x-site.seo-head>` renders the complete, brand-agnostic SEO head for every
project: canonical URL, robots, Open Graph / Twitter Card and JSON-LD
(Organization + BreadcrumbList). It honours the `SeoFields` kit's `meta.*`
overrides out of the box — `seo_title` (also used by the layout `<title>` via
the shared `SeoHead::title()` source), `seo_description`, `og_image_url` and
the `noindex` toggle (rendered as `<meta name="robots" content="noindex, follow">`).

Project-specific SEO rules plug into seams on two levels instead of copying
the view:

**Type-owned rules → the blueprint.** When the rule is a property of the
content type itself, override `noindex()` in that type's blueprint — the rule
lives next to the fields it reads and ships with the type:

```php
// app/Sites/{Site}/{Type}/Blueprint.php
public function noindex(Content $content): bool
{
    return data_get($content->payload, 'is_vergeben') === true;
}
```

**Cross-cutting rules and extra JSON-LD → the SeoHead registry**
(`Mmoollllee\Cms\Support\Seo\SeoHead`), registered in a service provider's
`boot()` — for rules that span content types or depend on tenant/environment
state:

```php
use Mmoollllee\Cms\Support\Seo\SeoHead;

// Force noindex beyond the editorial toggle and the blueprint signal:
SeoHead::noindexWhen(fn (?Content $content, Tenant $tenant): bool
    => $tenant->isStagingDomain());

// Emit additional JSON-LD blocks (return null to skip for a page):
SeoHead::addSchema(fn (?Content $content, Tenant $tenant): ?array => [
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => $tenant->displayName(),
]);
```

Resolution order of `SeoHead::isNoindex()`: editorial `meta.noindex` toggle →
the content type's `ContentBlueprint::noindex()` → registered
`noindexWhen()` rules; first hit wins.

Rules receive the current content (nullable — error pages) and the resolved
tenant; every registered schema is encoded with the hardened JSON-LD flags
(`JSON_HEX_TAG` keeps `</script>` breakouts inert). `SeoHead::reset()` clears
the seams in tests. A full app-side view override of
`components/site/seo-head.blade.php` remains the last resort — it forfeits
central updates, which is exactly the drift these seams exist to prevent.
When adding JSON-LD anywhere, build the schema arrays inside a `@php` block —
a literal `'@context'` in template text is compiled as Blade's `@context`
directive (Laravel 12) and corrupts the key. Guards:
`tests/Feature/SeoHeadFallbackTest.php` (brand-agnostic contract) and
`tests/Feature/SeoHeadExtensionTest.php` (meta overrides + seams).

Fallback UI strings are translatable (`lang/de` + `lang/en`, namespace `cms::`,
publish tag `cms-lang`); the app locale (`APP_LOCALE`) picks the language.

---

## 11. Vendored Filament view overrides

The package replaces Filament's builder rendering with two vendored views
(`resources/overrides/filament-forms/`): `filament-forms::components.builder` and
`…builder.block-picker`. They carry the four builder UX features that have no Filament
extension point: cross-builder drag & drop, inline preview editing, the inactive-block
UI, and the clipboard-paste picker entry. Everything else (copy action, options gear,
headings) lives in PHP and survives Filament updates.

Since Filament 5.7 the builder has **no vendor Blade view** — it renders PHP-side via
`Builder::toEmbeddedHtml()`. The package re-enters the classic view path by pinning the
view name globally (`Builder::configureUsing(fn ($b) => $b->view('filament-forms::components.builder'))`
in `CmsServiceProvider`); `prependNamespace` then resolves that name to the override,
and the picker Blade component the view renders resolves through the same namespace.

The builder view is a Blade translation of the **filament v5.7.1**
`toEmbeddedHtml()`/`generateBlockPickerHtml()` markup, the picker a vendored copy of the
still-existing vendor view — every divergence wrapped in `cms:start`/`cms:end` markers.
`tests/Feature/FilamentViewOverrideDriftTest.php` hashes those vendor sources (method
source + picker file) — when a Filament update changes them, the test fails with
re-vendoring instructions (translate/copy the vendor changes, re-apply the marked
blocks, update the hash). Nothing drifts silently.

## 12. Media library (optional)

With `ralphjsmit/laravel-filament-media-library` installed (commercial — install steps
in the [README](../README.md#optional-media-library-mediathek)), the engine wires a
per-tenant media library automatically; without it, every media field is a classic
tenant-scoped `FileUpload`. All wiring gates on
`Mmoollllee\Cms\Support\Media\MediaLibrary::enabled()`.

**Registry knobs** (app service provider, like every other `Cms::` option):

```php
Cms::disableMediaLibrary();                      // classic uploads even when installed
Cms::useMediaDriver(MyDriver::class);            // extend CmsMediaLibraryDriver: scope, disk,
                                                 // conversions, accepted types (nest-style)
Cms::useMediaItemModel(MyItem::class);           // must extend the plugin's MediaLibraryItem
Cms::useMediaDisk('media-library');              // e.g. a private disk with an own
                                                 // media-library.url_generator (policy-gated serving)
Cms::useMediaFolderNames([                       // rename the default per-tenant folders
    MediaFolders::BRANDING => 'Marke',
    MediaFolders::PAGES => 'Inhalte',
    MediaFolders::DOCUMENTS => 'Downloads',
]);
```

**Panel options** — override `BasePanelProvider::mediaLibraryPlugin()` to change
navigation/slug/accepted types or swap the library page; the driver stays the single
owner of behavior. The `MediaPickerPreviewAction` (arrow-key navigation, PDF preview) is
registered globally via `MediaPicker::configureUsing()` in the package boot — an app's
own `configureUsing` (booting later) overrides it.

**Fields** — use the dual-mode factory in your own blocks/resources instead of raw
components, and chain only methods that exist on BOTH `MediaPicker` and `FileUpload`:

```php
MediaField::image('image_path', legacyDirectory: static::uploadDirectory($tenant))
MediaField::media('media_path', ...)                      // images + videos
MediaField::document('file_path')                         // PDF/ZIP/Office → „Dokumente"
    ->label('…')->helperText('…')->acceptedFileTypes([...])
```

Values are media item ids (or legacy paths — same keys, both render). Resolve them via
`AssetUrlResolver::resolve($ref, ?conversion)` / `MediaUrlResolver` (`srcset()`, `alt()`,
`isVideo()`, `preload()`) or the `<x-site.image :media="$ref" :sizes="…">` component.
Keep refs inside `blocks`/`payload` JSON or `$fillable` columns — the draft stash
overlays via `fill()`, relations would bypass it.

**Authorization** — `MediaItemPolicy`/`MediaFolderPolicy` (tenant members manage the
current tenant, superadmin bypass via `before()`) are registered on the plugin models in
`CmsServiceProvider`; re-register with `Gate::policy()` to replace them. Item/folder
visibility itself comes from the driver's tenant scope (auto-active whenever a Filament
tenant exists).

**Legacy migration** — `cms:media:import` (idempotent; `--dry-run`, `--tenant=`,
`--all`, `--sync`) imports every referenced file and rewrites refs to ids, draft stashes
included. Run it BEFORE editors touch the panel — a picker cannot hydrate a raw path and
would drop it on save. Originals stay on disk; prune later.
