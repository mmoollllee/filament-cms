<?php

namespace Mmoollllee\Cms\Filament\Providers;

use Awcodes\RicherEditor\Plugins\EmbedPlugin;
use Awcodes\RicherEditor\Plugins\IdPlugin;
use Awcodes\RicherEditor\Plugins\SourceCodePlugin;
use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;
use Datlechin\FilamentMenuBuilder\MenuPanel\ModelMenuPanel;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider as FilamentPanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\CmsServiceProvider;
use Mmoollllee\Cms\Concerns\Tenant\HasSpamQuestions;
use Mmoollllee\Cms\Contracts\Tenant as TenantContract;
use Mmoollllee\Cms\Filament\Auth\TenantAwareLoginResponse;
use Mmoollllee\Cms\Filament\Concerns\LocksRecords;
use Mmoollllee\Cms\Filament\Locking\ResourceLockPlugin;
use Mmoollllee\Cms\Filament\Pages\Auth\EditProfile;
use Mmoollllee\Cms\Filament\Pages\Auth\Login;
use Mmoollllee\Cms\Filament\Pages\Auth\Register;
use Mmoollllee\Cms\Filament\Pages\Dashboard;
use Mmoollllee\Cms\Filament\Pages\Tenancy\EditTenantProfilePage;
use Mmoollllee\Cms\Filament\Pages\Tenancy\RegisterTenantPage;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\LayoutPresetResource;
use Mmoollllee\Cms\Filament\Resources\Locks\LockResource;
use Mmoollllee\Cms\Filament\Resources\Menus\MenuResource;
use Mmoollllee\Cms\Filament\Resources\NotFoundLogs\NotFoundLogResource;
use Mmoollllee\Cms\Filament\Resources\Redirects\RedirectResource;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\ButtonGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\NavigationCardGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\HtmlPreservePlugin;
use Mmoollllee\Cms\Filament\RichEditor\LinkPickerPlugin;
use Mmoollllee\Cms\Filament\RichEditor\MediaLibraryAttachmentPlugin;
use Mmoollllee\Cms\Filament\RichEditor\MediaLibraryPickerPlugin;
use Mmoollllee\Cms\Http\Middleware\RedirectUnauthorizedPanelAccess;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Analytics\Umami;
use Mmoollllee\Cms\Support\Branding\SiteTokens;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaLibraryFileAttachmentProvider;
use Mmoollllee\Cms\Support\Shortcodes;
use Mmoollllee\FilamentConsentControl\Filament\ConsentIframePlugin;
use Mmoollllee\Filami\Filament\Pages\UmamiStatistics;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;

/**
 * Shared multi-tenant admin panel for the CMS engine.
 *
 * Apps register a thin subclass (in bootstrap/providers.php) that overrides the
 * hook methods below. The base wires login/profile/tenant pages, the core
 * resources (Users, LayoutPresets), the dashboard, middleware, the menu builder
 * plugin and per-tenant branding. Everything app-specific is supplied by the
 * subclass, Filament-style: the tenant-profile page via tenantProfilePage(),
 * and panel options (vite theme, path, page discovery, plugins) fluently on the
 * Panel in configurePanel(). The standard RichEditor configuration (awcodes
 * plugins, custom blocks, toolbar) is provided here and overridable via
 * configureRichEditor().
 */
abstract class BasePanelProvider extends FilamentPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('panel')
            ->path('panel')
            ->login(Login::class)
            // NOT an open sign-up: the page refuses every visit that does not
            // carry a valid, unaccepted invitation token and sends it to the
            // login screen. It exists so an invited person without an account
            // has somewhere to land ({@see Register}).
            ->registration(Register::class)
            ->profile(EditProfile::class)
            ->tenant(Cms::tenantModel(), slugAttribute: 'primary_domain')
            ->tenantDomain('{tenant:primary_domain}')
            ->tenantProfile($this->tenantProfilePage())
            ->tenantRegistration(RegisterTenantPage::class)
            ->brandName(fn (): string => $this->panelBrandName())
            ->brandLogo(fn (): ?string => $this->panelBrandLogoUrl())
            ->brandLogoHeight('2.5rem')
            ->tenantMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label('Seiten-Einstellungen')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->sort(-10),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString($this->panelPrimaryColorStyles()),
            )
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->resources($this->panelResources())
            ->pages($this->panelPages())
            ->widgets([])
            ->middleware([
                ResolveTenantFromHost::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                RedirectUnauthorizedPanelAccess::class,
            ])
            // ResolveTenantFromHost populates the CurrentTenant singleton that the
            // content policies, resource queries and canAccess() checks all read.
            // It must also run on Livewire's /livewire/update requests — otherwise
            // CurrentTenant is null on every subsequent interaction (opening a
            // record, table actions, pagination) and authorization 403s, even
            // though Filament's own tenant (IdentifyTenant, persistent by default)
            // is still resolved. Registering it as persistent middleware re-runs it
            // on those requests alongside Filament's stack.
            ->persistentMiddleware([
                ResolveTenantFromHost::class,
            ])
            ->plugin($this->resourceLockPlugin())
            ->plugin(
                FilamentMenuBuilderPlugin::make()
                    ->usingResource(MenuResource::class)
                    ->usingMenuModel(Menu::class)
                    ->navigationGroup('Inhalt')
                    ->navigationLabel('Navigation')
                    // Cms::menuLocations() — shared with the cache invalidation
                    // (ContentCacheObserver / cms:clear-tenant-cache).
                    ->addLocations(Cms::menuLocations())
                    ->addMenuPanels([
                        ModelMenuPanel::make('Inhalte')
                            ->model(Cms::contentModel()),
                    ])
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_START,
                fn (): View => view('cms::filament.header-actions'),
            );

        // Optional media library — one "Mediathek" per tenant. Only registered
        // when the (commercial) plugin is installed and not opted out; without
        // it the media fields fall back to classic uploads.
        if (MediaLibrary::enabled()) {
            $panel->plugin($this->mediaLibraryPlugin());
        }

        return $this->configurePanel($panel);
    }

    /**
     * Editorial locking: opening a record claims it, everyone else gets the
     * blocking modal ({@see LocksRecords}).
     *
     * Polling is switched ON deliberately — it is the presence heartbeat, and
     * without it a lock is never refreshed after the page load, so a long edit
     * would silently expire mid-session. It runs WITHOUT `pollingVisible()`:
     * that modifier pauses `wire:poll` while the polling element is outside the
     * VIEWPORT, and the plugin renders its observer as a zero-height div at the
     * top of the page — scrolling into the block builder would stop the
     * heartbeat mid-edit. Livewire throttles polling in a backgrounded tab on
     * its own, which is the behaviour actually wanted here.
     *
     * The timeout is deliberately NOT set here: the model trait reads
     * `filament-resource-lock.lock_timeout` and never consults the plugin, so a
     * value set on the plugin alone would be dead configuration. It is bridged
     * into that config key by {@see CmsServiceProvider}, which
     * the plugin's own getter falls back to — one owner, and the manager page
     * and `clear-expired` agree with the model on what expired means.
     *
     * The lock manager lists locks across ALL tenants (it cannot do otherwise —
     * `resource_locks` has no tenant), so it stays out of the navigation and
     * behind the superadmin gate.
     */
    protected function resourceLockPlugin(): ResourceLockPlugin
    {
        return ResourceLockPlugin::make()
            ->userModel(Cms::userModel())
            ->resourceClass(LockResource::class)
            ->usesPollingToDetectPresence()
            ->presencePollingInterval(30)
            ->displayResourceLockOwner()
            ->limitedAccessToResourceLockManager()
            ->gate('cms.manage-locks')
            ->registerNavigation(false)
            ->unlockerLimitedAccess()
            ->unlockerGate('cms.take-over-lock');
    }

    /**
     * The configured media-library plugin (called only when
     * {@see MediaLibrary::enabled()}). Behavior — tenancy, disk, conversions,
     * item model — lives in the driver ({@see Cms::useMediaDriver()});
     * override this method for page-level options (navigation, slug, accepted
     * types, modal width).
     */
    protected function mediaLibraryPlugin(): FilamentMediaLibrary
    {
        $plugin = FilamentMediaLibrary::make()
            ->navigationLabel('Mediathek')
            ->navigationGroup('Inhalt')
            ->slug('mediathek')
            ->acceptVideo()
            ->acceptPdf()
            ->acceptZip()
            ->acceptMicrosoftWord()
            ->acceptMicrosoftExcel()
            ->acceptMicrosoftPowerPoint();

        // Mirror FilamentMediaLibrary::getDefaultDriver(): an EXPLICIT driver
        // skips that method, which is the only place plugin-level
        // ->conversions() / ->spatieTagsIntegration() reach the driver — an
        // app override chaining those would otherwise be silently ignored.
        return $plugin->driver(
            Cms::mediaDriver(),
            modifyDriverUsing: function (MediaLibraryItemDriver $driver) use ($plugin): MediaLibraryItemDriver {
                if (is_bool($conversionsEnabled = $plugin->areConversionsEnabled())) {
                    $driver->conversions($conversionsEnabled);
                }

                if (is_bool($tagsEnabled = $plugin->isSpatieTagsIntegrationEnabled())) {
                    $driver->spatieTagsIntegration($tagsEnabled, $plugin->getSpatieTagsIntegrationModifyQueryCallback());
                }

                return $driver;
            },
        );
    }

    public function register(): void
    {
        parent::register();

        // Host-aware login redirect: send fresh logins to the dashboard of the
        // tenant matching the request host. Apps may rebind to customize.
        $this->app->bind(LoginResponse::class, TenantAwareLoginResponse::class);
    }

    public function boot(): void
    {
        $this->configureRichEditor();
    }

    /**
     * Global RichEditor configuration for the CMS panel: the awcodes/richer-editor
     * plugins, the package's link picker + custom blocks + HTML preservation, and
     * the standard toolbar. Override in a subclass to customize per app.
     */
    protected function configureRichEditor(): void
    {
        // Optional consent-gated iframe embeds: wired only when the project installs
        // mmoollllee/filament-consent-control. The CMS engine offers the integration;
        // the consent config/policy stays in the project (multi-tenant friendly).
        $consentIframePlugin = ConsentIframePlugin::class;
        $consentEnabled = class_exists($consentIframePlugin);

        RichEditor::configureUsing(function (RichEditor $component) use ($consentIframePlugin, $consentEnabled): void {
            // Read per component, not hoisted out of the closure: this runs at
            // boot, and an app that calls Cms::disableMediaLibrary() afterwards
            // would otherwise still get the library wiring.
            $mediaLibraryEnabled = MediaLibrary::enabled();

            $plugins = [
                SourceCodePlugin::make(),
                IdPlugin::make(),
                LinkPickerPlugin::make(),
                EmbedPlugin::make(),
                // Keeps class-carrying <div>/<span> HTML intact through TipTap's
                // HTML→JSON→HTML roundtrip (the blocks' HTML tab depends on it), and
                // marks the editing surface `.richtext` from JS — never re-add that
                // class with extraInputAttributes(): it lands on the container that
                // also holds the side panels, and rearranges the editor UI.
                HtmlPreservePlugin::make(),
            ];

            // Editor uploads become Mediathek items rather than loose files on a
            // disk path, so every image on the site is managed the same way no
            // matter which field it entered through.
            //
            // The provider has to be attached TWICE, because the two ends find
            // it differently. The renderer reads it off a plugin. The editor
            // does not: `RichEditor::getFileAttachmentProvider()` goes through
            // `getContentAttribute()`, which is null unless the MODEL implements
            // HasRichContent and registers the attribute — and CMS rich text
            // lives inside a `blocks` builder, never as a model attribute. So on
            // the editor side the closures are wired directly; without them the
            // provider is silently absent, every id fails the disk lookup and
            // each image renders with its bare id as the src.
            if ($mediaLibraryEnabled) {
                $provider = MediaLibraryFileAttachmentProvider::make();

                // The only plugin here implementing HasFileAttachmentProvider,
                // and so the one the renderer resolves URLs through. MediaPlugin
                // below has a getFileAttachmentProvider() method but does not
                // implement that interface, so it is never consulted for one.
                $plugins[] = MediaLibraryAttachmentPlugin::make();

                // Insert an image by PICKING one from the Mediathek — the same
                // MediaPicker the media fields use, so an editor meets one
                // library everywhere instead of a bare upload box in the editor
                // and a picker two fields down. It also carries the item's alt
                // text and lets a conversion be chosen. Its modal uploads too
                // (SelectFileAction builds the topbar with `uploadAction(true)`),
                // which is why no separate upload button is wired below.
                //
                // Registered on the component rather than the model, for the
                // reason given above; only its PROVIDER would have needed the
                // model route, and that half is covered by the closures.
                $plugins[] = MediaLibraryPickerPlugin::make();

                $component
                    ->getFileAttachmentUrlUsing(fn (mixed $file): ?string => $provider->getFileAttachmentUrl($file))
                    ->saveUploadedFileAttachmentUsing(
                        fn (TemporaryUploadedFile $file): mixed => $provider->saveUploadedFileAttachment($file),
                    );
            }

            // Toolbar's last group; the consent-iframe button slots in next to embed.
            $embedGroup = ['undo', 'redo', 'sourceCode', 'customBlocks', 'embed', 'mergeTags'];

            if ($consentEnabled) {
                $plugins[] = $consentIframePlugin::make();
                array_splice($embedGroup, 5, 0, 'consentIframe');
            }

            $component
                ->plugins($plugins)
                ->customBlocks([
                    ButtonGroupBlock::class,
                    NavigationCardGroupBlock::class,
                ])
                // The merge-tag picker (labels from Shortcodes::mergeTags());
                // values resolve tenant-aware on render via Shortcodes.
                ->mergeTags(Shortcodes::mergeTags())
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'linkPicker'],
                    ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                    ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                    // One upload route, not two: the Mediathek modal already
                    // uploads, and does it better — with a folder, alt text and
                    // a conversion. `attachFiles` would be a second, poorer one.
                    $mediaLibraryEnabled ? ['table', 'mediaLibrary'] : ['table', 'attachFiles'],
                    $embedGroup,
                ])
                // Drag-and-drop and paste, without the button. Filament derives
                // `hasFileAttachments()` from `attachFiles` being in the toolbar
                // and gates both gestures — plus saveUploadedFileAttachment()
                // itself — on it, so dropping the button alone would switch them
                // off. This sets the flag directly instead.
                //
                // Which also means it survives a field that declares its own
                // toolbar: `toolbarButtons()` clears every registered button
                // modification, and anything derived from the toolbar goes with
                // it. This is not.
                //
                // Null, not false, in the off case: null keeps Filament's own
                // default, which is the `attachFiles` button listed above.
                ->fileAttachments($mediaLibraryEnabled ?: null);
        });
    }

    /**
     * The tenant profile page (registered via ->tenantProfile()). Defaults to the
     * package page — branding/contact/SEO plus, when the tenant model uses
     * {@see HasSpamQuestions}, the spam section. Override
     * to point at an app subclass that adds project-specific profile fields.
     *
     * @return class-string
     */
    protected function tenantProfilePage(): string
    {
        return EditTenantProfilePage::class;
    }

    /**
     * Resources registered in the panel. The default is the full CMS composition:
     * the catch-all content resource (Cms::contentResource()), fragments (when
     * a fragment model is configured), the package core resources, and every
     * site-extension resource. Override only to add app resources or reorder.
     *
     * @return array<int, class-string>
     */
    protected function panelResources(): array
    {
        return [
            Cms::contentResource(),
            ...(Cms::fragmentModel() !== null ? [FragmentResource::class] : []),
            ...$this->coreResources(),
            ...app(SiteExtensionRegistry::class)->allResources(),
        ];
    }

    /**
     * Resources the package itself ships (admin-global, not content).
     *
     * @return array<int, class-string>
     */
    protected function coreResources(): array
    {
        return [
            RedirectResource::class,
            NotFoundLogResource::class,
            LayoutPresetResource::class,
            UserResource::class,
        ];
    }

    /**
     * Panel pages. The shared dashboard, plus the analytics page when
     * mmoollllee/filami is installed — it hides itself from the navigation
     * while Umami is unconfigured, so an app without credentials sees no
     * dead menu entry.
     *
     * @return array<int, class-string>
     */
    protected function panelPages(): array
    {
        return [
            Dashboard::class,
            ...(Umami::installed() ? [UmamiStatistics::class] : []),
        ];
    }

    /**
     * Final hook for app-specific panel tweaks, Filament-style: extra page
     * discovery, plugins, a custom `->path()` or `->viteTheme()`.
     */
    protected function configurePanel(Panel $panel): Panel
    {
        return $panel;
    }

    protected function panelBrandName(): string
    {
        return $this->currentPanelTenant()?->displayName() ?? config('app.name');
    }

    protected function panelBrandLogoUrl(): ?string
    {
        return $this->currentPanelTenant()?->resolvedMainLogoUrl();
    }

    /**
     * Two palettes, one tenant color:
     *
     * - `--primary-*` re-skins Filament's own chrome (buttons, links, focus).
     * - the site design tokens ({@see SiteTokens}) re-skin what the panel shows
     *   OF the website: the RichEditor content and the builder previews, which
     *   are styled by the app's frontend CSS. Without them those areas fall back
     *   to the constant baked into the app's `@theme` block at build time — one
     *   fixed brand for every tenant, and wrong for all but one of them.
     */
    protected function panelPrimaryColorStyles(): string
    {
        $tenant = $this->currentPanelTenant();

        if ($tenant === null) {
            return '';
        }

        $colorVariables = collect(Color::hex($tenant->resolvedPrimaryColor()))
            ->map(fn (string $color, int $shade): string => "--primary-{$shade}: {$color};")
            ->implode(' ');

        // STYLES_AFTER puts this behind the theme stylesheet, so the runtime
        // tokens win over the build-time ones at equal specificity.
        return "<style>:root { {$colorVariables} } ".SiteTokens::cssBlock($tenant).'</style>';
    }

    protected function currentPanelTenant(): ?TenantContract
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof TenantContract ? $tenant : null;
    }
}
