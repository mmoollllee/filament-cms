<?php

namespace Mmoollllee\Cms;

use Datlechin\FilamentMenuBuilder\Models\MenuItem;
use Datlechin\FilamentMenuBuilder\Models\MenuLocation;
use Filament\Resources\Events\RecordUpdated;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\Cms\Console\Commands\ClearTenantCacheCommand;
use Mmoollllee\Cms\Console\Commands\InstallCommand;
use Mmoollllee\Cms\Console\Commands\PruneNotFoundLogsCommand;
use Mmoollllee\Cms\Filament\Concerns\ManagesDrafts;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Observers\ContentCacheObserver;
use Mmoollllee\Cms\Observers\RedirectCacheObserver;
use Mmoollllee\Cms\Policies\ContentPolicy;
use Mmoollllee\Cms\Policies\TenantPolicy;
use Mmoollllee\Cms\Policies\UserPolicy;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\ContactLinkShortcodes;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
use Mmoollllee\Cms\Support\Content\PathGenerator;
use Mmoollllee\Cms\Support\Content\TemplateResolver;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Mmoollllee\Cms\Support\Routing\HitRecorder;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
use Mmoollllee\Cms\Support\Routing\PathSuggestionResolver;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;
use Mmoollllee\Cms\Support\Shortcodes;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\View\Components\LinkSuggestionsWrapper;

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

        // Override Filament's two builder views (builder + block-picker) to add
        // cross-builder drag & drop, inline preview editing, the inactive-block UI and
        // the clipboard paste entry (the view half of the TransfersBuilderItems /
        // PastesBuilderBlocks concerns). prependNamespace so these win over Filament's
        // originals.
        //
        // NOTE: both files are vendored copies (baseline filament/filament v5.6.8) with
        // the cms changes wrapped in `cms:start`/`cms:end` markers. Because this prepend
        // wins over the vendor views, a Filament update changing them would be silently
        // shadowed — tests/Feature/FilamentViewOverrideDriftTest.php hashes the vendor
        // files and fails loudly when they drift, with re-vendoring instructions.
        $this->app['view']->prependNamespace('filament-forms', __DIR__.'/../resources/overrides/filament-forms');

        // Client-side TipTap extensions (the JS halves of the package's PHP TipTap
        // extensions), loaded on demand by the RichEditor via HtmlPreservePlugin /
        // LinkPickerPlugin. Pre-built into resources/dist (`npm run build`);
        // `php artisan filament:assets` publishes them to the app's public dir.
        FilamentAsset::register([
            Js::make('tiptap-html-div', __DIR__.'/../resources/dist/tiptap-extensions/html-div.js')->loadedOnRequest(),
            Js::make('tiptap-html-span', __DIR__.'/../resources/dist/tiptap-extensions/html-span.js')->loadedOnRequest(),
            Js::make('tiptap-link-attributes', __DIR__.'/../resources/dist/tiptap-extensions/link-attributes.js')->loadedOnRequest(),
            Js::make('tiptap-link-bubble', __DIR__.'/../resources/dist/tiptap-extensions/link-bubble.js')->loadedOnRequest(),
            // Precompiled builder UX styles (inactive pill, preview interaction, inline
            // editing) — plain CSS, so every panel works without a custom vite theme.
            Css::make('filament-cms-builder', __DIR__.'/../resources/css/builder.css'),
        ], package: 'mmoollllee/filament-cms');

        // Both wire the registered Content/Tenant models, which are unset until
        // the app calls Cms::use*Model(). Skip them on an unconfigured install so
        // boot() doesn't fatal before the app is even set up.
        if (Cms::modelsConfigured()) {
            $this->registerCacheObservers();
            $this->registerPolicies();
        }

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
