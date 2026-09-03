<?php

namespace Mmoollllee\Cms;

use Datlechin\FilamentMenuBuilder\Models\MenuItem;
use Datlechin\FilamentMenuBuilder\Models\MenuLocation;
use Filament\Resources\Events\RecordUpdated;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\Cms\Console\Commands\ClearTenantCacheCommand;
use Mmoollllee\Cms\Console\Commands\InstallCommand;
use Mmoollllee\Cms\Console\Commands\MediaPruneCommand;
use Mmoollllee\Cms\Console\Commands\PruneNotFoundLogsCommand;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Mmoollllee\Cms\Filament\Concerns\ManagesDrafts;
use Mmoollllee\Cms\Filament\Providers\BasePanelProvider;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Models\TenantInvitation;
use Mmoollllee\Cms\Observers\ContentCacheObserver;
use Mmoollllee\Cms\Observers\RedirectCacheObserver;
use Mmoollllee\Cms\Policies\ContentPolicy;
use Mmoollllee\Cms\Policies\MediaFolderPolicy;
use Mmoollllee\Cms\Policies\MediaItemPolicy;
use Mmoollllee\Cms\Policies\TenantInvitationPolicy;
use Mmoollllee\Cms\Policies\TenantPolicy;
use Mmoollllee\Cms\Policies\UserPolicy;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Analytics\Umami;
use Mmoollllee\Cms\Support\Assets\ContentVersionedCss;
use Mmoollllee\Cms\Support\Assets\ContentVersionedJs;
use Mmoollllee\Cms\Support\ContactLinkShortcodes;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
use Mmoollllee\Cms\Support\Content\PathGenerator;
use Mmoollllee\Cms\Support\Content\TemplateResolver;
use Mmoollllee\Cms\Support\Locking\Locks;
use Mmoollllee\Cms\Support\Media\CmsMediaLibraryItemImageGenerator;
use Mmoollllee\Cms\Support\Media\MediaFolders;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Mmoollllee\Cms\Support\Routing\HitRecorder;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
use Mmoollllee\Cms\Support\Routing\PathSuggestionResolver;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;
use Mmoollllee\Cms\Support\Shortcodes;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Tiptap\Marks\LinkPicker;
use Mmoollllee\Cms\View\Components\LinkSuggestionsWrapper;
use Mmoollllee\Filami\Filami;
use RalphJSmit\Filament\MediaLibrary\ImageGenerators\MediaLibraryItemImageGenerator;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Tiptap\Marks\Link;

/**
 * Boots the shared CMS engine.
 *
 * Auto-discovered by Laravel (extra.laravel.providers). Registers the engine
 * services as singletons so request-scoped state (the resolved tenant) and the
 * cached registries are shared across the request. Consuming apps no longer
 * need to bind these themselves.
 */
class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cms.php', 'cms');

        $this->app->singleton(CurrentTenant::class);
        // Request-scoped draft-preview flag: set once by ResolveTenantFromHost,
        // read by the HasDraft retrieved-overlay and the frontend badge.
        // scoped(), not singleton(): the flag must never survive into the next
        // request on long-lived runtimes (Octane flushes scoped bindings).
        $this->app->scoped(PreviewMode::class);
        $this->app->singleton(SiteExtensionRegistry::class);
        $this->app->singleton(ContentBlueprintRegistry::class);
        $this->app->singleton(PathNormalizer::class);
        $this->app->singleton(HitRecorder::class);
        $this->app->singleton(RedirectResolver::class);
        $this->app->singleton(PathSuggestionResolver::class);
        $this->app->singleton(PathGenerator::class);
        $this->app->singleton(TemplateResolver::class);
        // Request-scoped preset cache: controllers preload() it, blocks resolve()
        // against it. Must be shared, or every resolve() hits an empty cache and
        // returns no classes (no grid layouts, no section headers).
        $this->app->singleton(LayoutPresetResolver::class);

        // The builder blocks offered in the panel, seeded from Cms::blocks()
        // (defaults to the four core blocks; apps replace the list via
        // Cms::registerBlocks()). MUST be a singleton — resources resolve it
        // repeatedly and an unshared registry would silently be empty. Resolved
        // lazily, so any provider may register blocks before first panel use.
        $this->app->singleton(BuilderBlockRegistry::class, function (): BuilderBlockRegistry {
            $registry = new BuilderBlockRegistry;

            foreach (Cms::blocks() as $blockClass) {
                $registry->register(new $blockClass);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        // Publishable config so apps can scaffold/override config/cms.php with
        // `php artisan vendor:publish --tag=cms-config`. Defaults are also merged at
        // register() time, so publishing is optional.
        $this->publishes([__DIR__.'/../config/cms.php' => config_path('cms.php')], 'cms-config');

        // The engine schema (tenants/contents/fragments/menus/layout_presets + the
        // is_superadmin / tenant_id alters). Publishable rather than auto-loaded: two
        // files ALTER the app's own base tables and must interleave with the app's
        // migrations, and the existing apps already carry their own copies. A fresh
        // consumer runs `php artisan vendor:publish --tag=cms-migrations` then migrate.
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'cms-migrations');

        // Default block views: `blocks::media.preview` (Filament builder previews) and
        // `<x-block::media>` (frontend render). Apps can override individual views by
        // publishing them, or register their own block path for project-specific blocks.
        $blocks = __DIR__.'/../resources/blocks';

        $this->loadViewsFrom($blocks, 'blocks');
        Blade::anonymousComponentPath($blocks, 'block');

        $this->publishes([$blocks => resource_path('views/vendor/blocks')], 'cms-blocks');

        // General package views (widgets, panel partials) under the `cms` namespace.
        // Apps override individual views by publishing them to views/vendor/cms.
        $views = __DIR__.'/../resources/views';

        $this->loadViewsFrom($views, 'cms');
        $this->publishes([$views => resource_path('views/vendor/cms')], 'cms-views');

        // Styled field wrapper for the ContentPathSuggestions inputs (two-line
        // link suggestions). Filament resolves field wrappers as dynamic Blade
        // components, so the view needs a component alias:
        // ->fieldWrapperView('cms-link-suggestions-wrapper').
        Blade::component(LinkSuggestionsWrapper::class, 'cms-link-suggestions-wrapper');

        // Frontend/error/mail fallback strings under the `cms::` namespace
        // (lang/de + lang/en; the app locale picks the language). Apps adjust
        // single strings by publishing to lang/vendor/cms.
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cms');
        $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/cms')], 'cms-lang');

        // Default <x-site.*> design components (section-header, listing-card,
        // media-item) the block views render with. Registered as a FALLBACK: the
        // consuming app's own resources/views/components/site/* take precedence
        // (their view path is checked first); these render when none exists (e.g.
        // a standalone install or the testbench).
        $this->app['view']->addLocation($views);
        $this->publishes([
            $views.'/components/site' => resource_path('views/components/site'),
        ], 'cms-site-components');

        // Brand-agnostic frontend view defaults (onepager shell, layout, footer,
        // partials, content/page, errors). Apps override any of these by placing a
        // view at the same path (the app view path wins over this fallback); publish
        // them to customize a copy.
        $this->publishes([
            $views.'/frontend' => resource_path('views/frontend'),
            $views.'/partials' => resource_path('views/partials'),
            $views.'/content' => resource_path('views/content'),
            $views.'/errors' => resource_path('views/errors'),
            $views.'/components/site' => resource_path('views/components/site'),
        ], 'cms-frontend');

        // Override Filament's builder rendering (builder + block-picker) to add
        // cross-builder drag & drop, inline preview editing, the inactive-block UI and
        // the clipboard paste entry (the view half of the TransfersBuilderItems /
        // PastesBuilderBlocks concerns).
        //
        // Since Filament 5.7 the Builder ships NO Blade view — it renders PHP-side via
        // toEmbeddedHtml() (HasEmbeddedView), and its published-view override check only
        // looks at resource_path('views/vendor/…'), which a package cannot serve. A
        // component with an EXPLICIT view skips the embedded path entirely — the
        // package's BlockBuilder factory pins the classic view name on every CMS
        // builder (see BlockBuilder::make()), and this prepend makes the override win
        // the namespace lookup (the block-picker Blade component our view renders
        // resolves through the same namespace). Builders outside the CMS keep
        // Filament's embedded rendering untouched.
        //
        // NOTE: both files are vendored equivalents (baseline filament/filament v5.7.1;
        // the builder view mirrors Builder::toEmbeddedHtml()) with the cms changes
        // wrapped in `cms:start`/`cms:end` markers. Because this shadows vendor
        // rendering, a Filament update changing it would be silently swallowed —
        // tests/Feature/FilamentViewOverrideDriftTest.php hashes the vendor sources and
        // fails loudly when they drift, with re-vendoring instructions.
        $this->app['view']->prependNamespace('filament-forms', __DIR__.'/../resources/overrides/filament-forms');

        // The link mark the RichEditor renders with — the picker's, carrying its
        // extra attributes (class, title, wire:navigate).
        //
        // It REPLACES the stock mark instead of being registered alongside it:
        // tiptap-php's DOMSerializer opens a tag for EVERY registered mark named
        // `link`, so a second one nests the anchors (`<a class=…><a href=…>`).
        // Nested <a> is invalid HTML — the next parse keeps only the inner tag,
        // and every attribute the outer one carried is silently dropped on save.
        //
        // Filament's RichContentRenderer resolves Tiptap\Marks\Link from the
        // container, which awcodes/richer-editor already rebinds to its own
        // subclass — hence boot(): a register()-time bind would lose the race
        // when that provider happens to register last.
        $this->app->bind(Link::class, LinkPicker::class);

        // Client-side TipTap extensions (the JS halves of the package's PHP TipTap
        // extensions), loaded on demand by the RichEditor via HtmlPreservePlugin /
        // LinkPickerPlugin. Pre-built into resources/dist (`npm run build`);
        // `php artisan filament:assets` publishes them to the app's public dir.
        // ContentVersioned*, not Filament's Js/Css: the default cache-busting
        // version is the installed COMPOSER version, so an asset edited within a
        // release keeps its exact URL and every browser that has already seen it
        // serves the stale copy — see HasContentHashVersion.
        FilamentAsset::register([
            ContentVersionedJs::make('tiptap-html-button', __DIR__.'/../resources/dist/tiptap-extensions/html-button.js')->loadedOnRequest(),
            ContentVersionedJs::make('tiptap-html-div', __DIR__.'/../resources/dist/tiptap-extensions/html-div.js')->loadedOnRequest(),
            ContentVersionedJs::make('tiptap-html-span', __DIR__.'/../resources/dist/tiptap-extensions/html-span.js')->loadedOnRequest(),
            ContentVersionedJs::make('tiptap-link-attributes', __DIR__.'/../resources/dist/tiptap-extensions/link-attributes.js')->loadedOnRequest(),
            ContentVersionedJs::make('tiptap-link-bubble', __DIR__.'/../resources/dist/tiptap-extensions/link-bubble.js')->loadedOnRequest(),
            ContentVersionedJs::make('tiptap-rich-text-surface', __DIR__.'/../resources/dist/tiptap-extensions/rich-text-surface.js')->loadedOnRequest(),
            // Precompiled builder UX styles (inactive pill, preview interaction, inline
            // editing) — plain CSS, so every panel works without a custom vite theme.
            ContentVersionedCss::make('filament-cms-builder', __DIR__.'/../resources/css/builder.css'),
            // Precompiled diff styles for the revisions pages (the plugin's own
            // CSS is Tailwind source and would need a custom theme build).
            ContentVersionedCss::make('filament-cms-versionable', __DIR__.'/../resources/css/versionable.css'),
        ], package: 'mmoollllee/filament-cms');

        // Both wire the registered Content/Tenant models, which are unset until
        // the app calls Cms::use*Model(). Skip them on an unconfigured install so
        // boot() doesn't fatal before the app is even set up.
        $this->registerMenuTenantScope();

        if (Cms::modelsConfigured()) {
            $this->registerCacheObservers();
            $this->registerPolicies();

            // Versions record their author via versionable.user_model — point it
            // at the registered user model (the packaged default assumes
            // \App\Models\User, which the workbench/testbench doesn't have).
            // Retention is env-driven via cms.versions.keep (HasVersions
            // force-deletes pruned rows, so the cap actually frees storage).
            config([
                'versionable.user_model' => Cms::userModel(),
                'versionable.keep_versions' => (int) config('cms.versions.keep', 50),
            ]);
        }

        // Editorial locking reads its timeout from the vendor config, NOT from
        // the plugin: HasLocks::getLockTimeout() consults
        // `filament-resource-lock.lock_timeout` directly, so a value set only
        // on the panel plugin would be dead and every expiry check would fall
        // back to the vendor default of 600s. Bridged here so the model, the
        // lock manager and `clear-expired` all agree.
        config(['filament-resource-lock.lock_timeout' => Locks::timeout()]);

        // Optional Umami analytics (mmoollllee/filami): every tenant gets its
        // own Umami website, provisioned on creation and kept in sync on
        // rename/domain change. Storage + metadata go through filami's
        // attribute conventions (umami_website_id / name / primary_domain), so
        // app tenant models need no trait. Inert while UMAMI_URL is unset.
        if (Cms::modelsConfigured() && Umami::installed()) {
            Filami::autoProvision(
                Cms::tenantModel(),
                syncOn: ['name', 'primary_domain'],
                // Archived tenants are off the air; giving them a website would
                // only add permanent zero rows to the instance. Apps override
                // this by calling autoProvision() again from their own provider.
                when: fn (Model $tenant): bool => $tenant->getAttribute('visibility') !== TenantVisibility::Archived->value,
            );
        }

        // Optional media-library integration — wired only when the (commercial)
        // plugin is installed; without it every media field falls back to the
        // classic FileUpload/path behavior.
        if (MediaLibrary::installed()) {
            $this->registerMediaLibrary();
        }

        // The media caches are REQUEST-scoped by contract but live in statics.
        // terminating() runs per request under FPM and long-running runtimes
        // alike (Octane calls Application::terminate() after every request) —
        // without this, a worker would serve stale alt texts/URLs after an
        // editor changes media, and the caches would grow unbounded.
        $this->app->terminating(function (): void {
            MediaUrlResolver::flush();
            MediaFolders::flush();
        });

        $this->registerShortcodes();

        // Package frontend routes (the async /_resolve404 endpoint). Loaded during boot so it is
        // registered before the app's catch-all `/{path?}` and matched first.
        $this->loadRoutesFrom(__DIR__.'/../routes/frontend.php');

        // "Änderungen anwenden" clears the applied draft stash. Wired to
        // Filament's RecordUpdated event (EditRecord::save() dispatches it)
        // instead of the afterSave() hook, so a page subclass overriding that
        // common hook cannot silently break draft clearing. The page decides
        // (stale-tab guard) whether the stash may actually be dropped.
        // NOTE: Filament dispatches this as a string event with a payload
        // array — the listener receives (record, data, page), not an object.
        Event::listen(RecordUpdated::class, function ($record, array $data = [], $page = null): void {
            if ($page !== null && in_array(ManagesDrafts::class, class_uses_recursive($page), true)) {
                $page->handleAppliedDraft($record);
            }
        });

        // Daily pruning of stale, low-traffic 404 logs (runs only where a scheduler is configured).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('cms:prune-not-found-logs')->daily();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearTenantCacheCommand::class,
                InstallCommand::class,
                PruneNotFoundLogsCommand::class,
            ]);
        }
    }

    /**
     * Media-library plugin wiring (guarded by MediaLibrary::installed()):
     * Gate policies for the vendor models (not auto-discovered) plus the
     * legacy-import and disk-prune commands.
     *
     * Picker UX (upload button, dropzones, extended preview action) is NOT
     * wired here: it comes from the optional
     * mmoollllee/filament-media-library-extensions package, whose service
     * provider applies itself via configureUsing() — the driver default picks
     * up its opt-in trait automatically ({@see Cms::mediaDriver()}).
     */
    protected function registerMediaLibrary(): void
    {
        Gate::policy(MediaLibraryItem::class, MediaItemPolicy::class);
        Gate::policy(MediaLibraryFolder::class, MediaFolderPolicy::class);

        // The driver resolves its thumbnail generator through the container
        // (`app(MediaLibraryItemImageGenerator::class)`), so swapping the
        // binding is what reaches every picker, table column and infolist at
        // once — see the subclass for the guard it adds.
        $this->app->bind(MediaLibraryItemImageGenerator::class, CmsMediaLibraryItemImageGenerator::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                MediaPruneCommand::class,
            ]);
        }
    }

    /**
     * Register the spam-protected contact-link shortcodes ([contact_email_link] /
     * [contact_phone_link]; laravel-spamprotect is a package dependency). Uses the
     * Shortcodes extension hook so registration is deferred to first use (and
     * survives Shortcodes::reset() in tests), and so app-registered shortcodes
     * still compose alongside it.
     */
    protected function registerShortcodes(): void
    {
        Shortcodes::extendDefaultsUsing(fn () => ContactLinkShortcodes::register());
    }

    /**
     * Explicitly map the config-resolved models to the shared policies. Required
     * because the moved policies no longer live under the App\Policies convention
     * Laravel's policy guesser relies on.
     */
    protected function registerPolicies(): void
    {
        Gate::policy(Cms::contentModel(), ContentPolicy::class);
        Gate::policy(Cms::tenantModel(), TenantPolicy::class);
        Gate::policy(Cms::userModel(), UserPolicy::class);
        Gate::policy(TenantInvitation::class, TenantInvitationPolicy::class);

        $this->registerLockingGates();
    }

    /**
     * Authorization for editorial locking (blendbyte/filament-resource-lock,
     * wired in {@see BasePanelProvider}).
     *
     * - `cms.take-over-lock` decides who may seize a record another editor is
     *   holding ("übernehmen" in the blocking modal): tenant admins, since an
     *   editor stuck behind a colleague's forgotten tab needs someone able to
     *   resolve it before the timeout.
     * - `cms.manage-locks` guards the plugin's lock manager, which lists locks
     *   ACROSS tenants (its model has no tenant scope) — superadmins only.
     */
    protected function registerLockingGates(): void
    {
        Gate::define('cms.take-over-lock', function (User $user): bool {
            if ($user->isSuperadmin()) {
                return true;
            }

            $tenant = app(CurrentTenant::class)->get();

            return $tenant instanceof Tenant
                && $user->tenantRole($tenant) === TenantUserRole::Admin;
        });

        Gate::define('cms.manage-locks', fn (User $user): bool => $user->isSuperadmin());
    }

    /**
     * Confine the menu-builder's MenuItem to the current tenant.
     *
     * SECURITY. `tenant_id` sits on `menus`, not on `menu_items` — an item's
     * tenant is only implied by its `menu_id`. Filament's tenancy scope reaches
     * Menu (it backs a resource) but never MenuItem, and the plugin has no
     * policy, so its Livewire component passed client-supplied ids straight
     * into unfiltered queries: reorder() mass-updates `whereIn('id', $order)`,
     * and the edit/delete actions resolve by bare id. A tenant editor could
     * therefore read, re-parent, rewrite and delete another tenant's menu items
     * from their own menu page.
     *
     * A whereIn SUBQUERY (not whereHas) is deliberate: it survives the mass
     * update in MenuItemService::updateOrder(), which whereHas would not.
     *
     * Console and queue contexts have no CurrentTenant, so the scope is inert
     * there and seeding/imports keep working unchanged.
     */
    protected function registerMenuTenantScope(): void
    {
        MenuItem::addGlobalScope('cms_tenant', function (EloquentBuilder $query): void {
            $tenant = app(CurrentTenant::class)->get();

            if ($tenant === null) {
                return;
            }

            $query->whereIn(
                'menu_id',
                Menu::query()->where('tenant_id', $tenant->getKey())->select('id'),
            );
        });
    }

    /**
     * Wire the cache-invalidation observer to the config-resolved Content/Tenant
     * models + the package Menu, so frontend caches stay coherent on edits.
     */
    protected function registerCacheObservers(): void
    {
        $observer = new ContentCacheObserver;

        $content = Cms::contentModel();
        $content::saved(fn ($record) => $observer->contentSaved($record));
        $content::deleted(fn ($record) => $observer->contentDeleted($record));

        $tenant = Cms::tenantModel();
        $tenant::saved(fn ($record) => $observer->tenantSaved($record));
        $tenant::deleted(fn ($record) => $observer->tenantDeleted($record));

        // Keep the per-tenant active-redirect map coherent + warm when redirects change.
        Redirect::observe(RedirectCacheObserver::class);

        Menu::saved(fn (Menu $menu) => $observer->menuSaved($menu));
        Menu::deleted(fn (Menu $menu) => $observer->menuDeleted($menu));

        // Menu items + locations don't fire Menu events, so structure edits in the
        // panel would leave the per-tenant menu-link cache stale. Observe them too.
        // The package uses datlechin's default item/location models (no override),
        // and the plugin's model accessor needs a booted panel (unavailable here),
        // so reference the concrete classes directly.
        foreach ([MenuItem::class, MenuLocation::class] as $relatedModel) {
            $relatedModel::saved(fn (Model $record) => $observer->menuStructureChanged($record));
            $relatedModel::deleted(fn (Model $record) => $observer->menuStructureChanged($record));
        }
    }
}
