# mmoollllee/filament-cms

Shared CMS engine for multi-tenant marketing websites (Laravel 12 + Filament v5) — the
WordPress replacement for client projects. One updatable package carries the engine;
each site keeps its own models, content types, blocks, views and design.

- **Namespace:** `Mmoollllee\Cms\`
- **What it ships:** domain-based multi-tenancy with branding inheritance, the content
  engine (types/blueprints, hierarchical paths, publishing windows, templates), the
  customer-friendly block builder (previews with inline editing, copy/paste, drag & drop
  across sections, layout presets), the RichEditor stack (link picker with internal-path
  autocomplete, custom blocks, HTML preservation), redirects + 404 management with fuzzy
  auto-resolve, sitemap/robots, spam-protected contact output, video re-encoding, and
  the complete Filament admin panel.
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
  `layout_presets`, the menu tables, `redirects`/`not_found_logs` + the
  `users.is_superadmin` alter)
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
