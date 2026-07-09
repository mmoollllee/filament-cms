<?php

namespace Mmoollllee\Cms\Filament\Providers;

use Awcodes\RicherEditor\Plugins\EmbedPlugin;
use Awcodes\RicherEditor\Plugins\IdPlugin;
use Awcodes\RicherEditor\Plugins\SourceCodePlugin;
use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;
use Datlechin\FilamentMenuBuilder\MenuPanel\ModelMenuPanel;
use Filament\Actions\Action;
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
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Tenant\HasSpamQuestions;
use Mmoollllee\Cms\Contracts\Tenant as TenantContract;
use Mmoollllee\Cms\Filament\Auth\TenantAwareLoginResponse;
use Mmoollllee\Cms\Filament\Pages\Auth\EditProfile;
use Mmoollllee\Cms\Filament\Pages\Auth\Login;
use Mmoollllee\Cms\Filament\Pages\Dashboard;
use Mmoollllee\Cms\Filament\Pages\Tenancy\EditTenantProfilePage;
use Mmoollllee\Cms\Filament\Pages\Tenancy\RegisterTenantPage;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\LayoutPresetResource;
use Mmoollllee\Cms\Filament\Resources\NotFoundLogs\NotFoundLogResource;
use Mmoollllee\Cms\Filament\Resources\Redirects\RedirectResource;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\ButtonGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\NavigationCardGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\HtmlPreservePlugin;
use Mmoollllee\Cms\Filament\RichEditor\LinkPickerPlugin;
use Mmoollllee\Cms\Http\Middleware\RedirectUnauthorizedPanelAccess;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Shortcodes;

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
            ->plugin(
                FilamentMenuBuilderPlugin::make()
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

        return $this->configurePanel($panel);
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
        $consentIframePlugin = \Mmoollllee\FilamentConsentControl\Filament\ConsentIframePlugin::class;
        $consentEnabled = class_exists($consentIframePlugin);

        RichEditor::configureUsing(function (RichEditor $component) use ($consentIframePlugin, $consentEnabled): void {
            $plugins = [
                SourceCodePlugin::make(),
                IdPlugin::make(),
                LinkPickerPlugin::make(),
                EmbedPlugin::make(),
                // Keeps class-carrying <div>/<span> HTML intact through TipTap's
                // HTML→JSON→HTML roundtrip (the blocks' HTML tab depends on it).
                HtmlPreservePlugin::make(),
            ];

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
                    ['table', 'attachFiles'],
                    $embedGroup,
                ]);
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
     * Panel pages. Defaults to the shared dashboard.
     *
     * @return array<int, class-string>
     */
    protected function panelPages(): array
    {
        return [
            Dashboard::class,
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

    protected function panelPrimaryColorStyles(): string
    {
        $tenant = $this->currentPanelTenant();

        if ($tenant === null) {
            return '';
        }

        $colorVariables = collect(Color::hex($tenant->resolvedPrimaryColor()))
            ->map(fn (string $color, int $shade): string => "--primary-{$shade}: {$color};")
            ->implode(' ');

        return "<style>:root { {$colorVariables} }</style>";
    }

    protected function currentPanelTenant(): ?TenantContract
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof TenantContract ? $tenant : null;
    }
}
