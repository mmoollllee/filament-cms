# mmoollllee/filament-cms

Shared CMS engine for multi-tenant marketing websites (Laravel 12 + Filament v5) — the
WordPress replacement for client projects. One updatable package carries the engine;
each site keeps its own models, content types, blocks, views and design.

- **Namespace:** `Mmoollllee\Cms\`
- **What it ships:** domain-based multi-tenancy with branding inheritance, the content
  engine (types/blueprints, hierarchical paths, publishing windows, templates), a
  draft/preview workflow ("Entwurf speichern" + session-sticky Vorschau mode that
  overlays pending changes everywhere they render), snapshot versioning with
  side-by-side diffs, restore and a tenant-wide "Letzte Änderungen" dashboard widget
  (drafts stay out of the history), the customer-friendly block builder
  (previews with inline editing, copy/paste, drag & drop across sections, layout
  presets), the RichEditor stack (link picker with internal-path autocomplete, custom
  blocks, HTML preservation), redirects + 404 management with fuzzy auto-resolve,
  sitemap/robots, spam-protected contact output, video re-encoding, and the complete
  Filament admin panel.
- **Feature reference (all of it, with examples):** [`docs/FEATURES.md`](docs/FEATURES.md)
- **Extension points:** [`docs/CUSTOMIZATION.md`](docs/CUSTOMIZATION.md)
- **Self-documenting demo:** [`workbench/`](workbench) (see below)

## Installation

The package auto-discovers its `CmsServiceProvider` (engine singletons incl. the block
registry, views, policies, cache observers, the async 404-resolver route, scheduled
pruning). The app provides models, config, a thin PanelProvider and its frontend views.

### 1. Composer

Composer only reads `repositories` from the ROOT composer.json — the entries this
package declares for its own dependencies are ignored in consuming apps. Every
client app must therefore declare ALL THREE repositories itself:

```jsonc
{
    "repositories": [
        // the package itself:
        { "type": "vcs", "url": "https://github.com/mmoollllee/filament-cms" },

        // dependencies of filament-cms that are not on Packagist:
        // — Filament's plugin registry (license required for awcodes/richer-editor):
        { "type": "composer", "url": "https://packages.filamentphp.com/composer" },
        // — the inline block-title macro package:
        { "type": "vcs", "url": "https://github.com/mmoollllee/filament-builder-title" }
    ],
    "require": {
        "mmoollllee/filament-cms": "^0.1"
    }
}
```

`composer install` needs an `auth.json` with your filamentphp.com credentials
(for the plugin registry). Everything else resolves from Packagist.

### 2. `cms:install`

```bash
php artisan cms:install
php artisan migrate
```

One command scaffolds the whole integration (idempotent — existing files are
skipped, `--force` overwrites):

- publishes `config/cms.php` (environment-driven settings only: branding
  tenant, dev-login prefill, redirect tunables)
- publishes the migrations (`tenants`, `tenant_user`, `contents`, `fragments`,
  `layout_presets`, the menu tables, `redirects`/`not_found_logs`, `versions`
  (content history) + the `users.is_superadmin` alter)
- writes `App\Models\{Content, Tenant, Fragment}` — thin models on the package
  traits, ready to extend
- writes `App\Providers\CmsServiceProvider` (registered in
  `bootstrap/providers.php`) — the structural engine wiring in code,
  Cashier-style: `Cms::useContentModel()` etc., plus optional block/site
  registration
- writes `App\Providers\Filament\PanelProvider` (registered in
  `bootstrap/providers.php`) — the complete admin panel: content + fragment
  resources, redirects, 404 log, layout presets, users, RichEditor stack,
  tenant branding, login redirect; panel options (path, vite theme) are set
  fluently on the Panel, Filament-style
- appends the tenant-scoped frontend routes (robots, sitemap, `/_content`,
  content catch-all) to `routes/web.php`
- runs `filament:assets`

Prefer wiring manually (or want to know what the pieces do)? Every step is
documented in [`docs/CUSTOMIZATION.md` §1–§2](docs/CUSTOMIZATION.md).

### 3. User model

The one file the installer won't touch. Add the CMS wiring to
`App\Models\User`:

```php
use Mmoollllee\Cms\Concerns\User\BelongsToTenants;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants,
    \Mmoollllee\Cms\Contracts\User
{
    use BelongsToTenants;   // tenants()/roles + host-aware Filament tenancy methods
}
```

Keep `is_superadmin` **out of `$fillable`** — it is a global authorization
kill-switch; set it explicitly (factory state / seeder), never from request data.
Then create the first tenant + superadmin (e.g. via tinker) and open `/panel`
on the tenant's domain.

### 4. Content types, blocks & views (optional)

- **Content types:** site-specific types live in `app/Sites/<Name>/…` and are
  auto-discovered — blueprint + resource + three 3-line page classes per type
  ([`docs/CUSTOMIZATION.md` §3](docs/CUSTOMIZATION.md#3-site-extensions-blueprints--content-resources)).
  Without any, the package's `default.page`/`default.section` still give you a
  working sites + pages setup.
- **Project blocks:** register your block classes via
  `Cms::registerBlocks([...Cms::defaultBlocks(), MyBlock::class])` — the
  registry, the pickers and the section allowlists pick them up
  ([§8](docs/CUSTOMIZATION.md#8-blocks)).
- **Views:** the package ships brand-agnostic fallbacks for every frontend view
  (shells, content template, error pages, `<x-site.*>` components, block views) —
  your app overrides any of them by shipping the same view path, or publishes
  them as a starting point (`--tag=cms-frontend`, `--tag=cms-site-components`,
  `--tag=cms-blocks`).
- **Frontend JS:** the Alpine components those views bind against (`siteOnepager`,
  `siteChildNavigation`) ship as ES modules — architecture only (lazy loading,
  history, navigation context, menu state). Bundle them with your Vite build,
  register them on `alpine:init`, and layer brand behavior (scroll hints, hero
  fades, header measuring, …) on top via override factories and the viewport-state
  hooks ([`docs/CUSTOMIZATION.md` §10](docs/CUSTOMIZATION.md#10-frontend-views--js)):

  ```js
  // resources/js/app.js
  import { registerCmsFrontend } from '../../vendor/mmoollllee/filament-cms/resources/js/frontend/index.js';
  import onepagerOverrides from './site/onepager';   // your brand mixins (optional)

  document.addEventListener('alpine:init', () => {
      registerCmsFrontend(window.Alpine, { onepager: onepagerOverrides });
  });
  ```

### 5. Assets + deploy

```bash
php artisan filament:assets   # Filament CSS/JS + the package's TipTap extensions
npm install && npm run build  # your vite panel theme + frontend assets
php artisan optimize:clear    # ⚠️ on every deploy — stale route/Filament caches 500
```

### Optional: GDPR consent

The engine ships the *wiring* for a consent banner + content blocking — a
`<x-consent-control-banner>` slot in the site layout (with the boot config) and a
consent-gated iframe button in the RichEditor. It activates automatically **only if the
project installs the consent layer**. The CMS keeps **no** consent config of its own, so
each project (tenant) owns its categories, cookie settings and policy:

```bash
composer require mmoollllee/filament-consent-control
php artisan vendor:publish --tag=consent-control-config   # your categories, cookie + links
```

The runtime JS and overlay CSS are **bundled by the project** (the layout emits only the
inline boot config), so they ship with your Vite build instead of extra requests:

```js
// resources/js/app.js
import '../../vendor/mmoollllee/laravel-consent-control/resources/dist/js/consent-control.js';
```

```css
/* resources/css/app.css — overlay CSS + let Tailwind style the banner Blade */
@import '../../vendor/mmoollllee/laravel-consent-control/resources/dist/css/consent-message.css';
@source '../../vendor/mmoollllee/laravel-consent-control/resources/views/components/**/*.blade.php';
```

The banner inherits the site's design tokens and `.btn` component classes. To let visitors
**reopen the banner** (e.g. from the privacy policy page), place a button with the
`consent-control--open` class anywhere — the runtime binds it automatically:

```html
<button type="button" class="consent-control--open">Cookie-Einstellungen ändern</button>
```

Without the package, the banner and the RichEditor iframe button are simply absent — no
error. See
[`mmoollllee/filament-consent-control`](https://github.com/mmoollllee/filament-consent-control).

### Optional: Umami analytics

The engine ships the wiring for self-hosted [Umami](https://umami.is) via
[`mmoollllee/filami`](https://github.com/mmoollllee/filami). Once the package is
installed, every tenant automatically gets its own Umami website (created on tenant
registration, kept in sync on rename/domain change), the site layout renders the
cookie-less tracking snippet (production only by default), and a "Statistiken" page
joins the panel with per-tenant widgets: live visitors, visitors/pageviews/visit
time/bounce rate vs. the previous period, a visitors chart, the top pages and the
recorded events, all sharing one reporting-window select. The page and every widget
hide themselves while the package or credentials are missing — no error, no config
in the CMS itself, and the dashboard stays about the editorial work.

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/mmoollllee/filami" }
]
```

```bash
composer require mmoollllee/filami
php artisan vendor:publish --tag=cms-migrations   # three add_umami_* migrations
php artisan migrate
php artisan filami:sync                           # backfill websites for existing tenants
```

⚠️ `vendor:publish` copies the **whole** migration directory and does not
reliably recognise files your app already ran under a different timestamp —
check `git status database/migrations` afterwards and delete anything
unexpected. Add `umami_url`, `umami_website_id` and `umami_replay` to your
tenant model's `$fillable` (plus `'umami_replay' => 'bool'` in `casts()`), or
the panel form discards them without an error.

Session replay and heatmaps load a second script and are gated behind a
consent category, which the CMS does **not** declare for you — see the consent
section above. Declare it in your `config/consent-control.php` and bump that
file's `version`, otherwise the recorder waits for a category your banner
never offers and stays inert forever.

```dotenv
UMAMI_URL=https://a.example.com
UMAMI_USERNAME=provisioner
UMAMI_PASSWORD=secret
```

Env is optional for plain tracking: each tenant can name its own Umami server
and website id under *Seiten-Einstellungen → Statistik*, which is all the
snippet needs — handy when a customer runs their own instance. The credentials
above stay global and gate only auto-provisioning and the dashboard widgets.

Deploying the Umami instance itself (Plesk + Docker Compose): see filami's
`docs/deploy-plesk.md`.

#### Events: phone/mail clicks and form conversions

The layout also ships `<x-filami::events />`, so clicks are counted wherever
they appear, with nothing to wire up: `tel:` and `mailto:` links (including the
ones an editor typed into rich text, which are obfuscated against scrapers and
carry `data-filami-event` instead), and any link leaving the site
(`outbound-click`, recorded with the target host).

A site served from several of its own domains can declare the others, and a
hop between them then counts as internal rather than outbound. Leaving it
unset is equally valid — a second domain is often its own destination, and a
visitor moving there is a result worth measuring:

```dotenv
UMAMI_INTERNAL_DOMAINS=example.com,shop.example.com
```

Public forms built on `AbstractTenantAwareForm` measure their funnel by naming
themselves:

```php
protected ?string $analyticsName = 'contact-form';

public function submit(): void
{
    // … validate, send …

    // Last line of submit(): only a submission that produced mail is a
    // conversion. A form that fails validation or trips the honeypot is not.
    $this->trackConversion(['type' => 'general']);
}
```

Render the attributes on the `<form>` tag and the other half follows — a
`contact-form-start` the first time a visitor touches any field, so the
`contact-form-submit` count reads as a completion rate:

```blade
<form wire:submit="submit" {!! $this->analyticsAttributes() !!}>
```

Both are no-ops when the form is unnamed or filami is absent, so the same
template works in either case.

⚠️ Event properties are stored next to the pageview in Umami. Pass categories
(which variant, which product), **never** anything about the sender — no name,
address, phone or message text. The events widget breaks an event down by these
properties, so whatever goes in is what an editor reads back out.

Editorial `mailto:`/`tel:` links are obfuscated against scrapers, so filami
cannot recognise them by their href. `SpamprotectHtml` labels them with
`data-filami-event` instead — deliberately not Umami's own `data-umami-event`,
whose click handler would fight the one that decrypts the address.

### Optional: Media library („Mediathek")

The engine ships the complete *wiring* for
[Filament Media Library Pro](https://filamentplugins.com/filament-media-library-pro)
(commercial, by Ralph J. Smit) as a WordPress-style per-tenant media library: panel page,
tenant-scoped driver + policies, picker fields on every media input (blocks, hero,
branding incl. favicon, per-content OG image), an extended preview action (arrow-key
navigation, inline PDF preview), default folders (Branding / Seiten / Dokumente) and a
legacy importer. It activates automatically **only if the project installs the plugin**:

```bash
composer config 'repositories.ralphjsmit/*' composer https://satis.ralphjsmit.com
composer require ralphjsmit/laravel-filament-media-library spatie/laravel-medialibrary
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag=medialibrary-migrations
php artisan vendor:publish --tag=filament-media-library-migrations
php artisan migrate
```

The plugin needs a license (`auth.json` credentials for `satis.ralphjsmit.com` — also on
the deploy target, `auth.json` is usually gitignored) and its CSS imported into your
Filament theme:

```css
/* resources/css/filament/theme.css */
@import '../../../vendor/ralphjsmit/laravel-filament-media-library/resources/css/index.css';
```

Then migrate existing file references (paths in blocks/payload/drafts + tenant branding
become media items; run BEFORE editors use the panel — pickers cannot hydrate raw paths):

```bash
php artisan cms:media:import --dry-run
php artisan cms:media:import          # add --sync without a queue worker, --all for orphans
```

**Recommended companion** — the free
[`mmoollllee/filament-media-library-extensions`](https://github.com/mmoollllee/filament-media-library-extensions)
adds the WordPress-grade picker UX: an upload button on every picker, inline/drag-and-drop
uploads with progress tiles, auto-selection of fresh uploads, and an extended preview
(arrow keys, PDF iframe, policy-aware URLs). The CMS wires it automatically when
installed (the default media driver switches to the extensions-aware subclass):

```jsonc
// composer.json — the package is distributed via GitHub, not Packagist:
{ "repositories": [{ "type": "vcs", "url": "https://github.com/mmoollllee/filament-media-library-extensions" }] }
```

```bash
composer require mmoollllee/filament-media-library-extensions
php artisan filament:assets   # ⚠️ also after every update — asset URLs are content-hashed
```

Without the plugin every media field falls back to the classic tenant-scoped
`FileUpload`, and stored path references keep rendering either way (the resolver
understands both). Wiring details + customization: [docs/CUSTOMIZATION.md §12](docs/CUSTOMIZATION.md).

## Testbench / demo

The package ships a standalone **two-tenant demo** under [`workbench/`](workbench)
(orchestra/testbench) that exercises every engine feature and **documents itself**: the
frontend is a marketing + documentation site for filament-cms, built with filament-cms.
Home (tenants, logins, feature matrix), *Features*, *Blocks* (live showcase incl. the
code that produces each block), *Customize* (the customization guide as seeded content)
and *HowTos* (custom blocks, TipTap extensions). The seeder
([`workbench/database/seeders/DatabaseSeeder.php`](workbench/database/seeders/DatabaseSeeder.php))
is written as executable documentation.

```bash
composer install                     # needs auth.json for packages.filamentphp.com
composer test                        # vendor/bin/pest
vendor/bin/testbench filament:assets # once after install — else the panel is unstyled
vendor/bin/testbench migrate:fresh   # migrate + seed the persistent serve DB
composer serve                       # http://127.0.0.1:8000
```

- **Frontend / docs site:** http://127.0.0.1:8000 — tenant A ("filament-cms",
  `site_key: marketing`, the branding source). http://localhost:8000 — tenant B
  ("Acme GmbH", `site_key: acme`) inherits A's branding: the multi-tenancy proof.
- **Admin panel:** http://127.0.0.1:8000/panel — edit the very content the site renders.
  Credentials are prefilled in local env: `admin@example.test` / `password`.


## Update-safety

Two Filament builder views are vendored (cross-builder drag & drop, inline preview
editing, inactive-block UI, clipboard paste — no extension points exist for these).
`tests/Feature/FilamentViewOverrideDriftTest.php` hashes the vendor originals and fails
with re-vendoring instructions whenever a Filament update touches them — see
[`docs/CUSTOMIZATION.md` §11](docs/CUSTOMIZATION.md#11-vendored-filament-view-overrides).
Everything else extends supported APIs.


## Silent failure modes

Four things in this engine fail without an error message: the content saves, the
panel looks fine, and the site is simply wrong. They come up often enough to be
worth naming.

### Root blocks other than `section` disappear from the builder

`Cms::rootBlockAllowlist()` defaults to `['section']`. A `text` or `media` block
stored at the top level of `contents.blocks` still **renders on the site** —
`<x-site.content-blocks>` iterates whatever it finds — but the page builder does
not list it, so an editor can neither see nor change it, and saving the page drops
it.

Either wrap top-level content in a section block (what the builder expects), or
widen the allowlist for that site:

```php
Cms::allowRootBlocks('my-site', ['section', 'text', 'media']);
```

### The parent select offers sections that cannot work

`TenantScopedContentResource::getParentOptions()` returns every content of an
allowed parent type. On an onepager that is every section, while usually exactly
one of them renders the type in question — its template is the one calling
`visibleChildren()`. Choosing any other saves cleanly, shows the record as
published, and the site never displays it. `allowedParentTypes` is not enforced on
save either.

Narrow the options in the resource so the engine can preselect — and, where only
one section qualifies, drop the select entirely:

```php
protected static function getParentOptions(?Tenant $tenant, ?string $contentType, ?Model $record = null): array
{
    $options = parent::getParentOptions($tenant, $contentType, $record);

    $ids = Cms::contentModel()::query()
        ->whereBelongsTo($tenant)
        ->where('template', 'content.section-downloads')
        ->pluck('id')
        ->all();

    // Fall back to the full list, or a tenant without that section yet cannot
    // create records at all.
    return array_intersect_key($options, array_flip($ids)) ?: $options;
}
```

### Legacy file paths in media fields erase themselves on save

`MediaField::image()/media()/document()` return a `MediaPicker` whenever the media
library is enabled, and the picker can only hydrate a library item id. Given a
plain storage path — seeded content, or data from before the library — it starts
empty, and saving writes that emptiness back over the path. The frontend hides the
problem until then, because `MediaUrlResolver::url()` maps legacy paths to
`/storage/…` perfectly well.

Migrate the references once, before anyone opens the panel:

```bash
php artisan cms:media:import --sync
php artisan cms:clear-tenant-cache
```

### `content`-scope layout presets never reach the frontend

`LayoutPresetResolver::resolve()` reads from a request cache that only
`preload()` fills, and all three frontend controllers call it with
`$content->blocks`. The `layout_preset_ids` on the content record itself are never
read, so a `content`-scope preset resolves to an empty string however correctly it
is configured.

Style a section wrapper from its own markup instead:

```css
.onepager-section:has(> .my-section) { padding: 0 }
```
