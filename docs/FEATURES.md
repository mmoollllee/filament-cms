# filament-cms — Feature Reference

Every feature the engine ships, in brief, with an example. For wiring a new app see the
[README](../README.md); for extension points see [CUSTOMIZATION.md](CUSTOMIZATION.md).
The [workbench demo](../workbench) exercises everything below — run `composer serve` and
browse it.

**Contents:**
[Multi-tenancy](#multi-tenancy) ·
[Content engine](#content-engine) ·
[Block builder](#block-builder) ·
[Layout presets](#layout-presets) ·
[Fragments](#fragments) ·
[RichEditor](#richeditor) ·
[Shortcodes](#shortcodes) ·
[Menus](#menus) ·
[SEO](#seo-sitemap-robots) ·
[Redirects & 404](#redirects--404-management) ·
[Media & video](#media--video-conversion) ·
[Admin panel](#admin-panel) ·
[Field kits](#field-kits) ·
[Frontend](#frontend-views--components) ·
[Spam protection](#spam-protection) ·
[Commands](#console-commands) ·
[Bundled packages](#bundled-packages)

---

## Multi-tenancy

**Tenant-by-host resolution** — `ResolveTenantFromHost` middleware matches the request
host against `tenants.primary_domain` and stores the match in the request-scoped
`CurrentTenant` singleton (`app(CurrentTenant::class)->get()`), which the whole engine
reads. Hits are cached forever (`tenant_domain:{host}`), misses never (a new domain works
immediately).

```php
Route::middleware(ResolveTenantFromHost::class)->group(function () {
    Route::get('/{path?}', ContentShowController::class)->where('path', '.*');
});
```

**Tenant visibility** — `TenantVisibility` (Public / Members / Archived) gates the whole
frontend via `EnsureTenantIsVisible`; members-only tenants require a logged-in tenant user.
A site can therefore be finished under a staging domain with the customer and go public by
flipping one field.

**Moving a tenant to another domain** — the tenant settings page carries the
`primary_domain` field, superadmins only: host resolution answers with the first tenant
matching the request host, so this is the field that decides *which site serves a domain*,
a strictly bigger power than editing one's own site (`canManageDomain()` to widen or
narrow). A pasted address-bar value is reduced to the bare host, the forever-cached lookup
lets go of the old domain, and the panel — itself domain-scoped — redirects to the new one
instead of stranding the editor on a URL that no longer resolves. Installs with
`mmoollllee/filami` re-point the tenant's Umami website in the same save
(`syncOn: ['name', 'primary_domain']`).

**Branding inheritance** — a tenant with empty `brand_name`, `brand_claim`,
`primary_color`, SEO defaults, contact fields or social links inherits them from the
*branding tenant* (`cms.default_branding_tenant_id`, default: lowest id). One brand
tenant can therefore drive any number of satellite domains; the demo's second tenant
(`localhost`) proves it with zero own branding.

**Design tokens** — `Support\Branding\SiteTokens` turns the tenant's `primary_color` into
the site palette (`--color-primary`, `--color-surface`, `--color-muted-text`,
`--color-on-light`, two gradients) and writes it in both places that render the site:
onto the frontend `<body>` and into the Filament panel's `<head>`. So a color change is
live without a rebuild, and RichEditor / builder previews show the tenant currently
selected in the panel rather than the value compiled into the app's stylesheet. The app's
own `@theme` block stays the build-time default. See the
[CSS & HTML standard](CUSTOMIZATION.md#css--html-standard-every-consumer-follows-this).

**Roles** — the `tenant_user` pivot carries a `TenantUserRole` (admin/editor). Both roles
manage content *and* the tenant settings page (branding, contact, SEO); only user
management is admin-only. `users.is_superadmin` unlocks cross-tenant administration and
layout presets. Policies: `ContentPolicy`, `TenantPolicy`, `UserPolicy` (auto-registered) —
override `TenantPolicy::update()` via your own `Gate::policy()` to restrict the settings
page to admins again.

**Model traits** — everything the engine expects from your models ships as traits
(`php artisan cms:install` scaffolds models that use them):

```php
class Content extends Model implements \Mmoollllee\Cms\Contracts\Content
{
    use AssignsCurrentTenant;    // creating(): tenant_id ??= CurrentTenant
    use ConvertsUploadedVideos;  // saved(): dispatch video re-encode for media blocks
    use GeneratesPathAndSlug;    // saving(): path/slug from title + blueprint
    use HasPublishingStatus;     // status()/isPublished()/scopeVisibleTo()/scopeOfType()
    use ResolvesLayoutPresets;   // resolvedLayoutPreset() → CSS classes
}

class Tenant extends Model implements \Mmoollllee\Cms\Contracts\Tenant
{
    use HasContents;             // contents() + visibleContents() (override to filter)
    use HasSpamQuestions;        // tenant-configured spam-protection questions
    use HasTenantUsers;          // users()/creator(), hasUser(), isVisibleTo()
    use InheritsBranding;        // the resolved*() branding cascade + SEO defaults
}

class User extends Authenticatable implements /* Filament tenancy contracts + */ \Mmoollllee\Cms\Contracts\User
{
    use BelongsToTenants;        // tenants()/roles + host-aware canAccessPanel()/getDefaultTenant()
}
```

## Content engine

**Content types (blueprints)** — a `Blueprint` class per type declares key, labels,
template, URL prefix, builder support, parents, onepager participation:

```php
class Blueprint extends ConfiguredContentBlueprint
{
    protected string $key = 'marketing.service';
    protected string $label = 'Service';
    protected bool $isRoutable = false;          // slug-only, no URL
    protected ?string $defaultTemplate = 'content.service';
}
```

**Site extensions** — `app/Sites/<Name>/SiteExtension.php` groups blueprints + Filament
resources per tenant `site_key`; `default` always loads, the tenant's `site_key`
extension loads on top. `DiscoversSiteBlueprints`/`DiscoversSiteResources` autodiscover
`<Type>/Blueprint.php` and `<Type>/Resource.php`. The package ships the `default`
extension (`default.page`, `default.section`) — a new app has pages with zero site code.

**Non-routable types** — `$isRoutable = false` swaps the "Pfad" input for a tenant-unique
slug, keeps `path` null and hides the type from routing/sitemap. For embedded-but-
referenceable records (FAQ entries, team members, services listed by a listing block).

**Page hierarchy** — pages nest under pages (`default.page` allows `default.page`
parents): the form offers an "Übergeordnete Seite" select (cycle-safe — a page can
never become its own descendant), the parent's edit page gets a "Seiten verwalten"
action, the table indents nested titles and offers grouping by parent, and the
breadcrumb trail derives from the chain (path segments as fallback for unlinked
content).

**Parent-driven paths** — for routable types WITHOUT a `urlPathPrefix`, the parent
defines the path prefix and the record only owns its last segment
(`/howto` + child → `/howto/custom-blocks`, previewed live in the form). Renaming
a parent **moves the whole subtree**; old URLs fall through to the redirect/404
pipeline, which logs and auto-resolves them. Types with a `urlPathPrefix` keep
their type-based paths — the prefix wins over the hierarchy.

**Path generation** — `GeneratesPathAndSlug` + `PathGenerator` derive collision-free
paths from title/prefix on save.

**Publishing** — `publish_from` / `publish_until` + `ContentStatus`
(Draft/Scheduled/Published/Expired). Guests only ever see published, public content
(`scopeVisibleTo`); superadmins and tenant members see everything (live preview of
drafts on the real site).

**Visibility** — `ContentVisibility` Public/Members per record: members-only pages 404
for guests.

**Template resolution** — `TemplateResolver` picks the Blade view:
`content.template` (per-record override) → blueprint `defaultTemplate()` →
`content.page`; each first tried site-specific (`{site_key}.content.page`), then global.

**Resolver caching** — `ContentResolver::findByPath()` caches hits forever and 404s for
60s, invalidated by `ContentCacheObserver` on every content/tenant/menu save — content
edits are live instantly, unchanged pages never query.

**Onepager** — a site can compose its frontend as a single scrolling page of section
contents (`default.section`): every section has its own path, every path renders the
same shell (`OnepagerShellController`); `ContentFragmentController` lazy-loads sections
via `/_content` (Alpine `siteOnepager`, shipped in `resources/js/frontend/` — see
[CUSTOMIZATION.md §10](CUSTOMIZATION.md#10-frontend-views--js)), with anchor deep-links,
scroll-synced URL/menu state and per-section teasers (`payload.has_teaser` + teaser
blocks). Sections are min-100vh with vertically centered content. The shell exposes
viewport-state extension hooks (`updateViewportState()`, `showLogo()`, `onResize()`)
so consuming apps can layer visual behavior — e.g. Münch's scroll-hint pills — on top
via override factories without forking the engine. The demo's second tenant
(localhost) is a live mini-onepager. The Seite/Sektion choice on the content form
is a BLUEPRINT flag (`$offeredInTypeSelect`): `default.section` ships with it off, and an
onepager site enables it by overriding that blueprint per site — so a pages-only brand
and an onepager brand share one installation without config switches; only routable,
offered types appear, rendered beside the title input.

**Navigation context** — `NavigationContextBuilder` gives every page breadcrumbs
(ancestor trail in one query), block anchors and sibling/child navigation for the
header partials; `backButton()` on the blueprint drives the "back to parent" pill.
The fallback header renders the trail with plain CSS truncation; richer behavior
(measured space fitting, logo-hover evade — see the muench-tiefbau.de reference
implementation) lives in app view/JS copies
([CUSTOMIZATION.md §10](CUSTOMIZATION.md#10-frontend-views--js)).

## Drafts & preview

**Draft workflow** — the content + fragment edit pages replace the single save with a
pair, rendered in the form footer AND the page header: **"Entwurf speichern"** stashes
the validated form state into the record's `draft` JSON column (the live site keeps
serving the applied version), **"Änderungen anwenden"** is the classic save — it applies
the form state and clears the stash. The draft button is disabled while the form has no
unsaved changes (client-side hash comparison against the last fill/stash/apply baseline
— same formula as Filament's unsaved-changes alert, but independent of that panel
option). Reopening the form continues on the draft (the
virtual `status` derives from the DRAFT publishing window), the subheading + a warning
badge in the list tables flag pending drafts, and **"Entwurf verwerfen"** (header, only
while a draft exists) drops the stash after confirmation. The delete action moved from
the header into the form footer as an icon-only trash button. Draft-only saves do NOT
invalidate the warm frontend caches.

**Creating as draft** — the create pages offer **"Als Entwurf anlegen"** beside
"Erstellen" (footer + header): the normal create pipeline runs, but the applied row is
neutralized — content pages persist with an EMPTY publishing window (unpublished, the
entered window only takes effect on apply), the fragment page without active blocks
(`hasContent()` false → renders nowhere, the branding cascade keeps serving its
fallback) — and the full form state lands in the draft stash. The redirect target (the
edit page) therefore opens directly in the draft workflow.

**Preview mode ("Vorschau")** — the header's eye action first stashes the CURRENT form
state as the draft (same validation as "Entwurf speichern"; on failure the tab closes
and the form shows the errors), then opens the record's frontend URL with `?preview=1`
in a new tab — the preview always shows exactly what the form shows. Fragments/
non-routable types open the homepage/parent — they preview wherever they are embedded.
The sticky session flag is scoped PER TENANT (shared-cookie multi-site installs don't
leak the mode across sites), never activates on panel or Livewire request paths (an
overlay there would corrupt admin write flows), and every guest-facing cache builder
(sitemap, sections, redirect map, menu links, 404 candidates) bypasses the overlay —
draft data cannot be frozen into caches guests read. Draft persistence itself is
column-targeted: stash/discard write only the `draft` column, so they can never carry
other attribute changes into the live row. The mode is session-sticky (leave via `?preview=0` or the
floating badge's "Beenden"), and only superadmins/members of the resolved tenant can
activate it. While active, EVERY retrieved Content/Fragment overlays its draft
(`HasDraft` hooks the `retrieved` event) — pending changes show up on the record's own
page, in listing blocks, fragments and onepager sections alike, with zero call-site
changes. Guests always get the applied version; preview requests bypass the path cache
(logged-in resolution always does) so drafts can never leak into cached responses.

**App wiring** — the concrete models opt in:

```php
class Content extends Model implements \Mmoollllee\Cms\Contracts\Content
{
    use \Mmoollllee\Cms\Concerns\HasDraft;   // + Fragment model alike
}
```

Existing installs add the `draft` column by copying the
`add_draft_column_to_contents_and_fragments` reconcile migration (fresh installs get it
via the create migrations). App-owned frontend shells include the floating indicator
once per layout: `@include('cms::partials.preview-badge')` (inline-styled, no Tailwind
build dependency). Models without the trait keep the classic save-only pages — every
draft UI element hides.

## Versioning & restore

**Snapshot history** — models adopting `HasVersions` (Content + Fragment, opt-in like
HasDraft) record a full snapshot version for every APPLIED change: create, "Änderungen
anwenden" and restores. Built on `overtrue/laravel-versionable` +
`mansoor/filament-versionable`; the acting panel user is stored per version. The
**draft workflow stays invisible to the history**: stashing/discarding creates no
version (column-targeted persistence fires no model events) AND `draft` is excluded
from the snapshot whitelist — the whitelist derives from `$fillable` minus
`draft`/`sort`/tenancy/authorship bookkeeping, so table reordering never spams the
timeline and app-added columns version automatically.

**Revisions UI** — the edit pages' header gains a "Revisionen" action (badge = number
of revisions, hidden until history exists) linking to a per-resource revisions page
with side-by-side diff, version browser and restore. The shared base is
`ContentRevisionsPage`; the catch-all and fragments ship theirs, a site resource adds
a 3-liner + one `getPages()` route (see the workbench Service resource). **Restore
discards a pending draft** (announced via notification) — the stash would otherwise
re-overlay the just-restored state — and the restore itself records a new version.
The diff styles ship precompiled (`versionable.css` FilamentAsset), so panels without
a custom vite theme render them too.

**Dashboard: Letzte Änderungen** — `RecentVersionsWidget` lists the tenant's most
recent applied changes across contents AND fragments (author, relative time, blueprint
type label) with deep links to the managing resource's edit + revisions pages
(type-specific site resources win over the catch-all via ContentResourceLocator).
Strictly tenant-scoped; drafts appear only after applying.

**App wiring** — `use HasVersions;` on Content + Fragment, copy the
`create_versions_table` migration (or publish it from overtrue — identical shape),
done. `versionable.user_model` is pointed at `Cms::userModel()` automatically. Models
without the trait hide every versioning element, including the widget — safe to
upgrade before adopting. Retention defaults to 50 versions per record
(`CMS_VERSIONS_KEEP`, 0 = unlimited); pruned versions are force-deleted so the cap
actually frees storage, and hard-deleting a record removes its history. Deep links in
the widget honor resource `canAccess()`.

## Editorial locking

**One editor per record** — opening an edit page claims the record for that user; anyone
else opening it gets a blocking modal ("wird gerade bearbeitet", with the holder's name)
and can only go back or — with the right role — take it over. Built on
`blendbyte/filament-resource-lock`: the engine uses its model layer, modal, presence
observer and lock manager, but drives them from its own page concern
(`LocksRecords`), because the vendor's page trait collides with the draft workflow's
form footer and assumes every model carries the lock trait.

**Which records** — everything with an edit page: contents, fragments, redirects, layout
presets and menus. On menus the lock is held on the menu itself while its items are
written by the builder's own panels — the modal covers the page before a second editor
reaches them.

**Presence heartbeat** — the panel polls every 30s, and locks time out after 180s
(`CMS_LOCK_TIMEOUT`, bridged into the vendor config — the lock model reads it from there,
not from the plugin). That pairing is deliberate: without polling a lock is never
refreshed after page load, so a long edit would silently expire. Livewire throttles the
poll on its own while the tab is backgrounded, so a forgotten tab ages out instead of
holding a record hostage; `pollingVisible()` is deliberately NOT used, because it keys on
the polling element being in the *viewport* and the observer sits at the top of the page —
scrolling into the builder would stop the heartbeat mid-edit. Closing the tab releases
the lock immediately when the browser still manages to fire the request; the timeout is
the real safety net.

**Writes are guarded, not just the UI** — a client that dismisses the modal still cannot
write: both "Änderungen anwenden" (`save()`) and "Entwurf speichern" refuse with a
notification and re-raise the modal. Guarding the draft path matters because `draft` is
a **single slot per record** — two editors share it, so an unguarded stash would
overwrite the holder's work without leaving a version behind.

**Take-over** — the modal's "übernehmen" button is gated by `cms.take-over-lock`
(tenant admins + superadmins), checked server-side too, since the event can be
dispatched by hand. The lock manager is genuinely cross-tenant (`resource_locks` has no
tenant column), so it is hidden from the navigation and gated by `cms.manage-locks`
(superadmins only) — and it opts out of tenancy **per class**, by redeclaring
`$isScopedToTenant`, never via `Resource::scopeToTenant(false)`: that static lives on the
`Resource` base class, so calling the setter on a subclass switches tenant scoping off for
every resource in the panel. The vendor's audit-log resource is not registered at all
(ungated by default, and its table is not part of the engine's schema).

**App wiring** — `use HasLocks;` on Content + Fragment (the `cms:install` stubs already
carry it), copy the `create_resource_locks_table` migration, done. Models without the
trait keep the unlocked flow, so upgrading before adopting is safe. Expired rows are
cleaned up lazily on the next visit and hourly by
`filament-resource-lock:clear-expired` — that one needs a running scheduler.

**The same user in two tabs** — a lock is held per USER, so a second tab of the same
person refreshes it instead of being blocked. That case (and any write from outside the
panel — imports, console commands, a shared account elsewhere) is covered by a second,
independent guard: `GuardsRecordWrites` fingerprints the record when the form is filled
and compares it against the stored row before every write. On drift the write is refused
with *"Der Inhalt wurde zwischenzeitlich anderswo bearbeitet. Bitte die Seite neu laden,
um keine Änderungen zu überschreiben."*

The fingerprint hashes the raw stored attributes, not `updated_at`: second precision
would let two writes inside one second compare equal, and bookkeeping-only writes would
compare unequal — reordering the content tree touches `sort` on rows an editor may have
open elsewhere, and blocking their save over a drag is noise, not safety. `sort`, the
timestamps and the tenancy/authorship columns are therefore excluded (override
`fingerprintIgnoredAttributes()` for app-specific machine-written columns); the `draft`
stash is deliberately **included**, since it is one shared slot per record. The guard
needs no lock package and no trait on the model — pages carrying `ManagesDrafts` or
`LocksRecords` have it automatically.

## Block builder

The customer-facing page composer, built on Filament's Builder field with a shared
factory so every builder (page, teaser, fragment, section children) behaves identically:

```php
BlockBuilder::make('blocks', $tenant, $blocks);                          // preview cards
BlockBuilder::make('blocks', $tenant, $blocks, previews: false);         // open forms
BlockBuilder::make('blocks', $tenant, $blocks, sortableGroup: 'section-blocks');
```

**Core blocks** — `section` (container: header w/ eyebrow + layout preset + intro text +
child blocks), `text` (rich text with HTML source tab), `media` (image/video upload with
alt text, poster frame, quality preset), `listing` (renders all visible records of a
content type as cards, e.g. services). Views ship in the package
(`blocks::…` previews, `<x-block::…>` frontend) and are `vendor:publish`-overridable.

**Block registration** — the package binds the `BuilderBlockRegistry` from
`Cms::blocks()` (defaults to the four core blocks). Add project blocks via
`Cms::registerBlocks([...Cms::defaultBlocks(), MyBlock::class])` in a service
provider — see the demo's "Custom blocks" HowTo.

**Inline preview editing** — with previews on, a block renders as a live preview card;
clicking it swaps to the edit form in place, "Fertig" returns to the preview. No modals.

**Copy & paste blocks** — every block row has a "Block kopieren" action (copies
type + data as JSON to the clipboard, localStorage fallback); every block picker offers
"Aus Zwischenablage einfügen" — including *across pages, tenants and browser tabs*.

**Cross-builder drag & drop** — blocks can be dragged between sections (all section
child-builders share the `section-blocks` sortable group); order in both lists is
preserved server-side (`TransfersBuilderItems`).

**Deactivate instead of delete** — the per-block gear ("Block-Optionen") has an "Aktiv"
toggle; inactive blocks stay in the builder dimmed, with an "inaktiv → aktivieren" pill
in the row header, and simply don't render on the site.

**Block options** — the gear action also carries the layout preset (scope-aware:
sections get `section` presets + a background-image upload, other blocks get
`section-child` presets), the heading level (h1–h3/none) and an anchor id for #-links.

**Editable block titles** — `->title('title', placeholder: 'Titel', suffix: 'Sektion')`
(from the bundled `mmoollllee/filament-builder-title`) renders an inline text input in
the block's header row — name blocks without opening them.

**Per-site allowlists** — restrict what editors can add:

```php
'blocks' => [
    'root_allowlist'          => ['landing' => ['section', 'hero']], // default: ['section']
    'section_child_allowlist' => ['landing' => ['text', 'media']],   // default: all but section
],
```

## Layout presets

Named, reusable Tailwind class sets (`LayoutPreset` model + resource, superadmin-only)
that customers pick from a dropdown — layout freedom without a CSS editor. Scoped via
`LayoutPreset::SCOPES`: `content` (page width), `section` (grid), `section-child`
(column span), `section-header`, `listing-wrapper`. Global or per-tenant; superadmins
can quick-create presets from any preset dropdown.

```php
LayoutPreset::create([
    'scope' => ['section'], 'type' => 'Columns',
    'title' => 'Three columns', 'classes' => 'md:grid-cols-3',
]);
LayoutPreset::selectField('section', $tenant); // the grouped picker used everywhere
```

Resolution is N+1-free: controllers `preload()` the request's preset ids once,
blocks/views resolve class strings from the request-scoped `LayoutPresetResolver`.

## Fragments

Reusable block groups (CTA banners, contact boxes) managed once, embedded anywhere.
`FragmentResource` ships in the package; the model resolves with the branding cascade —
a satellite tenant without its own `cta` fragment automatically uses the branding
tenant's:

```blade
@foreach (fragment_model('cta')?->blocks ?? [] as $block)
    <x-dynamic-component :component="'block::'.$block['type']" :data="$block['data']" />
@endforeach
```

## RichEditor

The package standardizes Filament's RichEditor across all projects
(`BasePanelProvider::configureRichEditor()`, overridable per app):

**Toolbar** — bold/italic/…, headings, alignment, lists, blockquote/code, tables,
attachments, undo/redo, source code, custom blocks, embeds, merge tags
(via the bundled `awcodes/richer-editor` Source/Id/Embed plugins).

**Link picker** — a WordPress-style modal replacing the bare link tool: URL with
**internal-path autocomplete** (type "kon…" → pick `/kontakt`, powered by
`Mmoollllee\Cms\Filament\Forms\ContentPathSuggestions` querying your Content model)
and `wire:navigate` up front; tooltip title, CSS classes and `rel` live in a
collapsed "Erweitert" section (auto-expanded when the edited link carries such
attributes). Clearing the URL and saving removes the link. Applies via editor
commands (`setLink`/`unsetLink`).

Clicking into an existing link shows a floating **link bubble** (WordPress-style):
the href plus "Bearbeiten" (re-opens the picker modal, prefilled) and "Entfernen"
(unsets the mark client-side) — `tiptap-extensions/link-bubble.js`.

The autocomplete is reusable in any resource form — suggestions render as styled
two-line entries (title + path) and can auto-fill a sibling label field with the
page title:

```php
use Mmoollllee\Cms\Filament\Forms\ContentPathSuggestions;

ContentPathSuggestions::makeHrefInputWithLabel('payload.link', 'payload.link_label')
    ->label('Link'),
```

For a full link field group with the **same options as the picker modal** (URL,
button label, wire:navigate, collapsed Erweitert section with tooltip title /
CSS classes / rel) use the `LinkFields` kit, and render the stored values with
`PayloadLink`:

```php
use Mmoollllee\Cms\Fields\LinkFields;

...LinkFields::make('payload.link')
    ->configure('url', fn ($field) => $field->helperText('Projektspezifisch'))
    ->toArray(),
```

```blade
@php $link = \Mmoollllee\Cms\Support\Content\PayloadLink::from($content->payload); @endphp
@if ($link->hasUrl())
    <a {{ $link->attributes(['class' => 'btn btn-sm']) }}>{{ $link->labelOr('Mehr erfahren') }}</a>
@endif
```

**Custom blocks** — block-level components inside rich text:
`ButtonGroupBlock` (CTA button rows — 7 variants, 3 sizes, icons, alignment) and
`NavigationCardGroupBlock` (mini teaser cards with label/description/arrow). Both render
through `<x-site.rich-editor.*>` views you can override per app.

**HTML preservation** — `HtmlPreservePlugin` + the package's TipTap extensions keep
class-carrying `<div>`/`<span>` markup — and `<button>` elements — intact through TipTap's
HTML→JSON→HTML roundtrip; without it, TipTap strips unknown markup. This powers the blocks'
**HTML source tab** (`BaseBuilderBlock::richEditorWithSource()`): Editor and raw-HTML tabs,
two-way synced. Buttons keep only `class` and `type` (default `button`), so editorial markup
like the consent banner's `<button type="button" class="consent-control--open">` survives
while inline handlers do not.

**TipTap extensions** — custom editor markup is always a pair: a PHP extension
(`Mmoollllee\Cms\Tiptap\*`, server-side rendering) and a JS extension
(`resources/js/tiptap-extensions/*.js`, editor-side), pre-built via esbuild
(`npm run build`) and published by `php artisan filament:assets`. The JS half
imports TipTap from `resources/js/tiptap-core.js`, which hands out the runtime
Filament already loaded (`window.FilamentRichEditor.tiptap.core`) — so each built
file stays under a kilobyte instead of carrying its own copy. Shipped:
`HtmlButton`/`HtmlDiv`/`HtmlSpan` (markup preservation), `link-attributes` (adds
title + wire:navigate to the link mark so the picker's fields survive re-editing),
`link-bubble` (the link picker's edit/unlink bubble) and `rich-text-surface`
(editor-only, no PHP half: puts `.richtext` on the ProseMirror element so the app's
content typography reaches the editing surface, not just the previews).
The demo's "TipTap extensions" HowTo walks through adding your own.

**Rendering** — `RichText::render($content)` renders stored rich text (TipTap JSON *or*
HTML) through the package `Renderer` (30+ TipTap PHP extensions), applies shortcodes and
spam-protects mailto/tel links. Use it in every view that outputs rich text.

## Shortcodes

WordPress-style `[tokens]` in any rich text, resolved against the current tenant:
`[company_name]`, `[contact_email]`, `[contact_phone]`, `[street]`, `[postal_code]`,
`[city]`, `[contact_address]`, `[logo]` — plus spam-protected `[contact_email_link]` /
`[contact_phone_link]` (rendered as obfuscated `<x-encrypt-email>`/`<x-encrypt-phone>`).
Editors insert them via the RichEditor's **merge-tag picker** (labels:
`Shortcodes::mergeTags()`, replaceable via `Shortcodes::useMergeTags()`).

```php
Shortcodes::extendDefaultsUsing(function (): void {
    Shortcodes::register('opening_hours', fn (array $attrs): string => '…');
    Shortcodes::registerMergeTagValue('opening_hours', fn () => '…');
});
```

Packages **add** a tag to whichever list the app runs with — `registerMergeTag()` is merged
on top of `useMergeTags()`, so an app curating its own labels does not have to know about
every installed package. `mmoollllee/filament-consent-control` uses it for the cookie-settings
button (`[consent_settings]`, picker label "Cookie-Einstellungen (Button)"), registering
itself when it is installed:

```php
Shortcodes::extendDefaultsUsing(function (): void {
    Shortcodes::registerMergeTag('consent_settings', 'Cookie-Einstellungen (Button)', fn () => new HtmlString('…'));
});
```

## Menus

`datlechin/filament-menu-builder`, tenant-scoped: drag-and-drop menus per tenant with a
"Inhalte" panel listing the tenant's pages, locations `header` + `footer` (extend via
`configurePanel()`), and a cached, link-ready lookup for views:

```php
Menu::linksForLocation('header', $tenant); // [['path' => '/features', 'label' => 'Features', …], …]
```

Each entry carries the item's own presentation metadata — `target`, `rel`, `classes`,
`icon` — alongside `path`/`href`/`label`, so a theme styles an entry from what the editor
set instead of inferring it. (Inferring is the trap: "the host differs, so it must be the
cross-site call to action" quietly claims every external link the site adds later.)

`icon` is a free-text field in the menu builder, so render it with `<x-site.menu-icon>`,
which drops an unresolvable name instead of throwing `SvgNotFound` on every page holding
the menu:

```blade
<a href="{{ $item['href'] }}" class="flyout-btn {{ $item['classes'] }}" target="{{ $item['target'] }}">
    <x-site.menu-icon :name="$item['icon']" class="size-4" />
    <span>{{ $item['label'] }}</span>
</a>
```

Cache invalidation covers menu, item and location changes (`ContentCacheObserver`).

## SEO, sitemap, robots

**SeoFields kit** — meta title (70), description (200), `noindex` toggle per record;
placeholders preview the computed defaults (tenant SEO settings + title composition).
All three are honoured by `<x-site.seo-head>` and the layout `<title>` out of the box.

**SeoHead seams** — projects extend the shared head without copying the view:
type-owned rules override `ContentBlueprint::noindex()` in the blueprint;
cross-cutting rules register `SeoHead::noindexWhen()`; extra JSON-LD via
`SeoHead::addSchema()` (hardened encoding). Resolution: editorial toggle →
blueprint → registry. See CUSTOMIZATION.md → "SEO head".

**Sitemap** — `SitemapController` serves `/sitemap.xml` per tenant: homepage, onepager
sections (as anchors), all routable published public content; cached per tenant.

**Robots** — `RobotsController` serves a `/robots.txt` pointing at the sitemap.

**Canonical trailing slash** — `CanonicalizeTrailingSlash` 301s `/pfad/` → `/pfad`
(preserves link equity from old WordPress URLs).

## Redirects & 404 management

A redirection.me-style subsystem, zero-DB-cost on the happy path:

- **`ResolveActiveRedirects`** middleware serves manual/automatic redirects from a
  per-tenant forever-cache (warmed on every redirect edit) *before* content resolution.
- **404 logging** — unmatched paths land in `not_found_logs` (deferred + throttled,
  bot-noise filtered via `ignore_extensions`), visible in the panel ("404-Log" resource,
  navigation badge = unresolved count) with a one-click "create redirect" action.
- **Fuzzy auto-resolve** — the branded 404 page calls `/_resolve404` asynchronously;
  `PathSuggestionResolver` scores the path against all visible content (slug match,
  similarity, token overlap). Score ≥ `auto_threshold` (0.92): the visitor is redirected
  and an *automatic* redirect (302) is persisted after `min_hits`; scores ≥
  `suggest_threshold` render as "Meinten Sie …?" links.
- **Redirect lifecycle** — `RedirectOrigin` Manual/Automatic/Suggested; an admin editing
  an automatic redirect promotes it to manual + 301 (`confirmed_status`). Deleting is
  soft — a trashed row blocks the resolver from re-creating the same automatic redirect.
- **Hit counting** (`hits`, `last_hit_at`, deferred) and **daily pruning** of stale
  low-traffic 404 logs (`cms:prune-not-found-logs`, auto-scheduled).

Everything is tunable under `config('cms.redirects')` — thresholds, statuses, retention.

## Media & video conversion

**Media library (optional)** — with `ralphjsmit/laravel-filament-media-library`
installed (commercial; see the README for setup), every media input becomes a
WordPress-style picker against one **per-tenant Mediathek**: panel page („Mediathek",
group Inhalt), folders, search, filters, bulk actions, image editor, central
`alt_text`/`caption`. The wiring the package ships:

- `MediaField` (image/media/document) renders a `MediaPicker` — or the classic
  tenant-scoped `FileUpload` when the plugin is absent (`MediaLibrary::enabled()` gate,
  opt-out via `Cms::disableMediaLibrary()`). Same data keys in both modes.
- `CmsMediaLibraryDriver` owns behavior: tenancy auto-detected from the Filament tenant
  (items + folders stamped and scoped per tenant), disk from `Cms::useMediaDisk()`
  (default `public`), policy re-bridge, conversions (`responsive`, `800`, `400`, `thumb`
  + `og` 1200×630 for social embeds) — swappable via `Cms::useMediaDriver()`.
- **Conversion budget** (`cms.media`) — the frontend conversions render as **WebP**
  (`format`, default `webp`; `og` stays `jpg` via `og_format` because messenger
  link-preview crawlers still lag on WebP), and the `responsive` conversion is capped to
  **1920px** (`max_width`, `Fit::Max` — never upsized). The cap matters more than it
  looks: srcset candidates are generated *from* the responsive conversion's file, so an
  uncapped 4000px phone photo otherwise yields several multi-megabyte candidates no
  viewport ever requests. Both settings affect NEW conversions only — see
  `cms:media:prune` for the leftovers.
- **References are item ids in the same JSON keys** that used to hold paths;
  `MediaUrlResolver` (behind the stable `AssetUrlResolver` façade) renders both — ints
  via the Spatie Media API (URL-generator-aware, so private-disk installs work),
  strings as before. `<x-site.image>` emits `srcset`/`sizes`/`loading="lazy"` from the
  responsive conversions; block trees batch-preload refs (no N+1).
- Default folders per tenant — flat, context-based: **Branding** (logos, favicon, OG),
  **Seiten** (block/hero media), **Dokumente** (downloads); provisioned lazily,
  renamable via `Cms::useMediaFolderNames()`.
- **Picker UX via `mmoollllee/filament-media-library-extensions`** *(optional, auto-wired
  when installed — the default driver switches to the trait-carrying subclass)*: upload
  button on the picker, inline/dropzone uploads with progress tiles, auto-selection of
  fresh uploads, and the extended preview action (arrow-key navigation, inline PDF
  preview, policy-aware URLs) on field/modal tiles and the file-info sidebar.
- **Editor uploads land in the Mediathek too.** `MediaLibraryAttachmentPlugin`
  carries a `FileAttachmentProvider` to both the RichEditor and the renderer, so an
  image pasted into a paragraph becomes a library item (tenant, folder `Seiten`,
  central alt text) instead of a loose file under `fileAttachmentsDirectory()`. The
  node's `data-id` holds the item id and `src` is regenerated from it. Filament reads
  the provider off the plugin on both ends — setting it on the field alone would store
  ids the frontend cannot resolve. Existing path-shaped ids keep rendering:
  `MediaUrlResolver` resolves ids and paths alike.
- **Legacy references** — raw file paths in blocks, payload, drafts and tenant
  branding, including images embedded in rich text, were converted to item ids by the
  one-time `cms:media:import`. It has been removed now that every install reports zero;
  v0.17.6 is the last release carrying it. `MediaUrlResolver` still resolves a stored
  path, so anything that was never migrated keeps rendering — but a file with no item
  is invisible to the Mediathek, to `cms:media:prune` and to any question about which
  media are still used, so deleting the directory it sits in looks safe from every
  angle the tooling can see.
- `cms:media:prune` reclaims disk the library never cleans up itself: conversions
  superseded by a format/size change (the library only knows the name it writes *today*,
  so a `jpg`→`webp` switch abandons the old file), srcset candidates dropped by a
  narrower `max_width`, and `{id}/` directories with no media row. Expected file names
  come from the media rows themselves, so pending conversions survive a scan; a legacy
  `2020/01/…` upload tree is **reported, never deleted** — a year is indistinguishable
  from an id, so only an admin can confirm the import is settled. `--dry-run`, and a
  confirmation prompt unless `--force`.

**Video conversion** — the media block accepts images and videos. Videos are
automatically made web-friendly: `ConvertsUploadedVideos` (Content model trait) detects
media blocks whose upload needs conversion (`.mov`/`.avi`/`.wmv` … or MP4 > 10 MB) and
dispatches `ConvertVideoForWeb` (queued, 10 min timeout, 2 tries): H.264 MP4, scaled to
≤1920px, CRF by the editor-chosen quality preset (high 23 / medium 28 / low 33), optional
audio strip, temp-file cleanup, `video_status` processing/complete/failed on the block.
Requires an `ffmpeg` binary on the server (bundled `pbmedia/laravel-ffmpeg`). Applies to
legacy path-based uploads; library uploads are served as uploaded (upload-time conversion
is on the roadmap, see `docs/KONZEPT-MEDIATHEK.md` P4).

## Admin panel

`BasePanelProvider` gives every project the same panel with one thin subclass:

- **Tenant switching** — Filament tenancy keyed by `primary_domain`, tenant menu with
  "Seiten-Einstellungen", per-tenant **branding in the panel itself** (brand name, logo,
  primary color as Filament palette).
- **Default resources** — catch-all "Seiten" (`Cms::contentResource()`), Fragments
  (when a fragment model is configured), Redirects, 404-Log, Layout-Presets (superadmin),
  Users, plus every site-extension resource. Override `panelResources()` only to add.
- **Content form** — title+slug/path input with live URL preview & visit link
  (`blendbyte/filament-title-with-slug`), tabbed layout (Inhalt / Teaser / Einstellungen),
  builder with sidebar (structure, template/layout, collapsed SEO "Meta"), opt-in
  "Titelbereich" page-header section, opt-in raw payload editor.
- **Duplicate** — every content row has "Duplizieren": modal prefilled with
  "… (Kopie)" + a fresh collision-free path/slug, copy starts as a draft, redirects
  straight into editing the copy.
- **Open** — every routable row links to its live URL in a new tab.
- **Topbar "Öffnen"** — the button beside the global search targets the frontend page of
  the record currently open in the panel (its own URL, the parent page for non-routable
  types) and falls back to the site's homepage everywhere else. It resolves from the
  current route (`FrontendLinkResolver`), so any panel resource whose model implements
  `Contracts\HasFrontendUrl` — site extensions included — gets it without wiring.
- **Parent-scoped listings** — child-type resources accept `?parent=` (heading
  "Services: Websites", create keeps the parent) and edit pages get generated
  "… verwalten" header actions for child types (blueprint `allowedParentTypes()`).
- **Dashboard** — tenant + content overview widgets (counts, status breakdown, quick
  links). Login (dev prefill via the `CMS_DEV_LOGIN_*` env vars, local env only), profile page,
  tenant registration + tenant profile (branding/contact/SEO/spam questions) pages
  included.
- **Base pages** — site resources' pages extend `ContentListPage` / `ContentCreatePage` /
  `ContentEditPage` and inherit everything above (incl. builder clipboard/DnD wiring and
  payload-preserving saves) in 3 lines per page.

## Field kits

Composable field sets (`FieldKit`) used across resources — compose, don't copy:

```php
SeoFields::make()->without('og_image')->toArray();
PublishingFields::make()->defaultVisibilityUsing(fn () => 'members')->toArray();
PageHeaderFields::make()->uploadDirectory("tenants/{$siteKey}/hero")->toArray();
MyKit::make()->prepend([$first])->extend([$extra])->only('title', 'subtitle');
```

Shipped: `SeoFields` (meta title/description/noindex), `PublishingFields` (status +
window + visibility), `PageHeaderFields` (`payload.hero.*`: size, title, subtitle,
thumbnail, image, CTA, float image) with the `RendersPageHeader` resource trait.

## Frontend views & components

Brand-agnostic fallbacks under the `cms::` namespace + root view locations — an app
overrides any of them by shipping the same path (`vendor:publish --tag=cms-frontend`):
`frontend/standalone` + `frontend/onepager` shells, `content/page`, branded `errors/404`
(with the async resolver + suggestions) and `errors/500`, header partials
(floating header, breadcrumbs, flyout), and the `<x-site.*>` design components
(`layout`, `content-blocks`, `section-header`, `listing-card`, `media-item`, `card`,
`button`, `footer`, rich-editor block views). Per-site error pages:
`{site_key}/errors/404.blade.php` wins over the shared one.

The Alpine components those views bind against (`siteOnepager`,
`siteChildNavigation`) ship as ES modules in `resources/js/frontend/` — architecture
only (lazy loading, history, navigation context, menu state). Apps bundle them via
their own Vite build, register them with `registerCmsFrontend(Alpine, overrides)` and
layer brand behavior (scroll hints, hero fades, header measuring, scroll stores) on
top through the override seam and the viewport-state hooks
([CUSTOMIZATION.md §10](CUSTOMIZATION.md#10-frontend-views--js)). Fallback UI strings
are translatable (`lang/de` + `lang/en`, publish tag `cms-lang`).

## E-mail layout

A shared, tenant-branded HTML e-mail layout so every mail the system sends carries the
same chrome — the tenant logo, primary color and contact footer — without each app
rebuilding it. Anonymous component under the `cms::` namespace:

```blade
{{-- resources/views/emails/whatever.blade.php --}}
<x-cms::mail :tenant="$tenant" heading="Neue Anfrage" preheader="Kurzvorschau im Postfach">
    <p>Freitext / Tabellen / was der Mailable braucht …</p>
</x-cms::mail>
```

The layout resolves branding through the standard cascade (`resolvedPrimaryColor()`,
`resolvedSiteSetting('company_name'|'street'|…)`), so it inherits from the branding tenant
like the frontend does. Props: `tenant` (falls back to the request-scoped `CurrentTenant`
when omitted — pass it explicitly for queued mail), `heading`, `preheader`, `title`
(defaults to heading → brand name), `footnote` (the small print above the copyright;
defaults to a German "automatisch versendet" note). Inline styles + a 600px table shell
keep it robust across mail clients; publish with `vendor:publish --tag=cms-views` to
override.

**Mail-safe logo** — the layout resolves the logo through `resolvedMailLogoUrl()`
(`Support\Mail\MailLogo`), not the raw frontend URL, because SVG doesn't render in Gmail
or any Outlook (only WebKit clients like Apple Mail show it) and data-URI embedding
doesn't rescue it. Resolution order: the tenant's **dedicated raster e-mail logo**
(`mail_logo_path`, a PNG/JPG uploaded on the profile "Marke" tab) → the main logo passed
through if it's already raster → the brand name as text. Both `mail_logo_path` and the
main-logo fallback follow the **branding cascade** (`resolveInheritedAssetPath`) — a
satellite tenant with no own value inherits the branding tenant's mail logo, exactly like
the frontend logo; an inherited dedicated mail logo takes precedence over the tenant's own
main-logo fallback. The profile field **previews the inherited default** (placeholder +
image) like the other brand assets, so admins see what an empty field will use. An SVG logo
is never linked, so when the site logo is an SVG, upload a PNG in the dedicated field to
show a logo in e-mail. Absolute URLs throughout (mail clients have no base URL).

## Spam protection

- **Obfuscated contact links** everywhere rich text renders (`SpamprotectHtml` converts
  `mailto:`/`tel:` links to `yannkuesthardt/laravel-spamprotect` components).
- **Spam quiz for forms** — tenants manage question/answer pairs in their profile
  (`HasSpamQuestions` tenant trait, seeded defaults in `DefaultSpamQuestions`); Livewire
  forms `use WithSpamQuiz` to render + validate a random question
  (`AbstractTenantAwareForm` is the tenant-aware Livewire form base to build on).

## Analytics events (optional, with `mmoollllee/filami`)

- **Phone/mail clicks** counted everywhere — plain `tel:`/`mailto:` anchors via the
  delegated listener in `<x-filami::events />`, obfuscated ones via the
  `data-filami-event` attribute `SpamprotectHtml` puts on them (name only: event data
  would write the address back into the markup; and not `data-umami-event`, whose
  click handler would race the one decrypting the address).
- **Outbound clicks** — any `http(s)` link to another host reports `outbound-click`
  with the target host. `UMAMI_INTERNAL_DOMAINS` declares further own domains as
  internal; leaving it unset counts a hop between them, which is usually what you
  want when the second domain is its own destination.
- **Form funnels** — a form on `AbstractTenantAwareForm` that returns a name from
  `$analyticsName` reports `<name>-start` on first interaction (render
  `{!! $this->analyticsAttributes() !!}` on the `<form>` tag) and `<name>-submit`
  from `trackConversion()`. Call the latter as the last line of `submit()`: bots,
  validation failures and mailer errors must not count as conversions.
- **Read back** on the panel's "Statistiken" page, in its events widget, which breaks an event down by the
  properties passed to `trackConversion()`. Those are stored next to the pageview —
  pass categories, never anything identifying the sender.

## Console commands

```bash
php artisan cms:install [--force]  # scaffold a fresh app: config (models pre-wired), migrations,
                                   # model + panel-provider stubs, frontend routes, filament:assets
php artisan cms:clear-tenant-cache [--tenant=ID] [--no-warm]  # flush + rewarm per-tenant caches
php artisan cms:prune-not-found-logs [--days=90] [--min-hits=3]  # scheduled daily
```

`cms:install` is idempotent: existing files are reported and skipped, so it is safe
on an already-integrated app (`--force` overwrites the scaffolding). The one manual
step it leaves: adding `Concerns\User\BelongsToTenants` + the contracts to your User
model (it never touches existing app code).

## Bundled packages

| Package | Used for |
|---|---|
| `mmoollllee/filament-builder-title` | inline editable block titles (`->title()` Block/Repeater macro) |
| `blendbyte/filament-title-with-slug` | title + slug/path input with URL preview |
| `datlechin/filament-menu-builder` | drag-and-drop menus (tenant-scoped by the package) |
| `defstudio/filament-searchable-input` | the internal-path autocomplete inputs |
| `blendbyte/filament-resource-lock` | the locks, blocking modal and presence heartbeat behind `HasLocks` |
| `mansoor/filament-versionable` | the revisions page (side-by-side diff, restore) |
| `overtrue/laravel-versionable` | the snapshot storage behind `HasVersions` |
| `awcodes/richer-editor` | RichEditor source-code / id / embed plugins |
| `ueberdosis/tiptap-php` | server-side rich-text rendering |
| `yannkuesthardt/laravel-spamprotect` | e-mail/phone obfuscation components |
| `pbmedia/laravel-ffmpeg` | the video re-encode job |
| `ralphjsmit/laravel-filament-media-library` | *(optional, commercial)* the per-tenant media library + picker |
| `spatie/laravel-medialibrary` | *(optional, with the plugin)* file storage/conversions backend |
| `mmoollllee/filament-media-library-extensions` | *(optional, with the plugin)* picker upload button, inline/dropzone uploads, extended preview |

`filament/filament` v5 and Laravel 12 are peer requirements of the consuming app.
