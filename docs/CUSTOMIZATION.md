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
  URL/title/indicator, anchor + history handling, hero-logo fade, and the scroll
  hints: pills labeled with the previous/next section title (`lg+` only) whose opacity
  follows the scroll position — it ramps with the free gap between the pill zone and
  the nearest section content (`SCROLL_HINT_FADE`), so a pill glimmers in shortly
  before the content edge clears its zone. The pills ride sticky on the middle of the
  empty band between two section contents: they rest at their fixed spot while the
  band middle is beyond it, anchor to the moving middle afterwards and dissolve toward
  the section handover point where the next boundary takes over
  (`SCROLL_HINT_CENTER_FADE` — so re-anchoring never pops visibly, even with very
  unequal content heights). Once the target section's own content shows in the
  viewport the pill fades out early (`SCROLL_HINT_TARGET_FADE`) — a pointer to
  something already on screen is noise. Hover/focus holds interactivity and forces
  full visibility; below `SCROLL_HINT_INTERACTIVE_MIN` the pill is disabled — no
  clicks, no focus — so a barely visible button can't be activated accidentally.
  Resting positions are measured from the actual buttons (at rest, cached while
  riding), so restyling them needs no JS change — but never CSS-transition the
  pill's translate: the ride tracks the scroll 1:1.
- **`siteChildNavigation`** — breadcrumbs, local-section tracking + flyout state on
  standalone pages.
- **header-bar fitting + logo evade** (`header-bar.js`, mixed into both components;
  the floating-header partial wires it via `x-init="initHeaderBar()"`) — the
  breadcrumb trail (home icon + ancestor links) and the indicator share the space
  left of the menu button by **measured** widths, not viewport breakpoints
  (`fitHeaderBar()`): ancestors drop root-first, the home icon only when even it
  alone has no room, and the indicator yields down to `HEADER_BAR.indicatorMin`
  before any breadcrumb is dropped (marqueeing once narrower than its label).
  Hovering the logo — whose expanded width comes from app CSS and is never assumed —
  starts a rAF loop that slides the breadcrumb nav and, where the logo reaches
  further, the indicator out of its way; both restore on mouseleave. The `HEADER_BAR`
  constants mirror the header partials' utility classes — keep them in sync when
  restyling.
- **`scroll` store** — window scroll progress (header progress bar + depth label).

```js
// resources/js/app.js
import { registerCmsFrontend } from '../../vendor/mmoollllee/filament-cms/resources/js/frontend/index.js';

document.addEventListener('alpine:init', () => {
    registerCmsFrontend(window.Alpine);
});
```

Project-specific frontend behavior stays in the app (e.g. münch's scroll reveal/zoom
effects live in the project bundle). To adjust a single engine behavior, pass override
factories — their members are spread over the package component:

```js
registerCmsFrontend(window.Alpine, {
    onepager: (el) => ({
        showLogo() { return true; },          // e.g. never hide the header logo
    }),
});
```

View contract of the onepager shell (`frontend/onepager.blade.php`): sections carry
`.onepager-section` + `data-path/-loaded/-title/-label/-navigation[/-anchor]`, the
scroll-hint buttons `data-scroll-hint="up|down"` + `x-ref`, an optional `.hero-logo`
inside the `/` section hides the header logo while visible. The hint chevrons are
inline SVG (self-contained — only the header partials require the app-registered
blade-icon set, e.g. `<x-icon-bars>`). The floating header's own contract:
`.logo-link`, `nav[data-role="header-breadcrumbs"]`, `[data-role="header-indicator"]`,
`.nav-menu-btn` plus the hidden measurer refs `indicatorMeasure`/`breadcrumbMeasure`
(their typography must match indicator and breadcrumb links) — header-bar.js locates
all of them by these hooks.

---

## 11. Vendored Filament view overrides

The package overrides exactly two Filament views (via `prependNamespace`):
`filament-forms::components.builder` and `…builder.block-picker`
(`resources/overrides/filament-forms/`). They carry the four builder UX features that
have no Filament extension point: cross-builder drag & drop, inline preview editing,
the inactive-block UI, and the clipboard-paste picker entry. Everything else
(copy action, options gear, headings) lives in PHP and survives Filament updates.

Both files are vendored copies of the **filament v5.6.8** originals with every
divergence wrapped in `cms:start`/`cms:end` markers.
`tests/Feature/FilamentViewOverrideDriftTest.php` hashes the vendor originals — when a
Filament update changes them, the test fails with re-vendoring instructions (copy the
new vendor view, re-apply the marked blocks, update the hash). Nothing drifts silently.
