<?php

namespace Mmoollllee\Cms\Filament\Support;

use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\Blocks\fragment\FragmentBlock;
use Mmoollllee\Cms\Support\Content\ContentResourceLocator;
use Throwable;

/**
 * Deep links from a place that SHOWS content to the panel page that EDITS it: the
 * "… verwalten" / "… bearbeiten" buttons of block previews (listing, fragment, an
 * app block standing in for records managed elsewhere) and the "Außerdem auf
 * dieser Seite" hints beside the content form.
 *
 * Every method answers null instead of a link the user could not follow — no
 * resource manages the target, the user may not open it, or no URL can be built
 * (rendered outside a panel request). A visible button must never lead into a 403.
 *
 * @phpstan-type Link array{url: string, label: string, icon: string|BackedEnum}
 */
final class ManagementLinks
{
    /**
     * The index of the resource that manages a content type, labelled
     * "<Plural> verwalten" and scoped to that type — the catch-all resource manages
     * many types, so an unscoped index would contradict the label.
     *
     * @return Link|null
     */
    public static function forContentType(string $contentType, ?Tenant $tenant): ?array
    {
        $resource = app(ContentResourceLocator::class)->resolve($contentType, $tenant);

        if ($resource === null || ! $resource::canAccess()) {
            return null;
        }

        $blueprint = app(ContentBlueprintRegistry::class)->find($contentType, $tenant?->site_key);

        // The icon the editor already knows from the navigation for this list.
        $icon = $resource::getNavigationIcon();

        return self::link(
            fn (): string => $resource::getUrl('index', ['type' => $contentType]),
            ($blueprint?->pluralLabel() ?? $contentType).' verwalten',
            is_string($icon) || $icon instanceof BackedEnum ? $icon : Heroicon::OutlinedRectangleStack,
        );
    }

    /**
     * The fragment's edit page. An inherited fragment (branding cascade) is edited
     * in its owner's panel — a link only for someone who may enter that panel.
     *
     * @return Link|null
     */
    public static function forFragment(Model $fragment, ?Tenant $tenant): ?array
    {
        if (Cms::fragmentModel() === null || ! FragmentResource::canEdit($fragment)) {
            return null;
        }

        $owner = self::isInherited($fragment, $tenant) ? self::owningTenant($fragment) : null;

        if ($owner !== null && ! self::userCanAccessTenant($owner)) {
            return null;
        }

        return self::link(
            fn (): string => FragmentResource::getUrl('edit', ['record' => $fragment], tenant: $owner),
            'Fragment bearbeiten',
            Heroicon::OutlinedPencilSquare,
        );
    }

    /**
     * The link for a fragment a template embeds by slug, named after it: its edit
     * page, or — while no fragment has that slug — the list to create it from.
     *
     * @return Link|null
     */
    public static function forFragmentSlug(string $slug, ?Tenant $tenant): ?array
    {
        $fragment = FragmentBlock::findFragment($tenant, $slug);

        if ($fragment === null) {
            $link = self::forFragmentList();

            return $link === null ? null : [...$link, 'label' => "Fragment „{$slug}“ anlegen"];
        }

        $link = self::forFragment($fragment, $tenant);
        $name = $fragment->getAttribute('title') ?: $slug;

        return $link === null ? null : [...$link, 'label' => "Fragment „{$name}“"];
    }

    /**
     * The fragment list — where a fragment that does not exist yet gets created.
     *
     * @return Link|null
     */
    public static function forFragmentList(): ?array
    {
        if (Cms::fragmentModel() === null || ! FragmentResource::canAccess()) {
            return null;
        }

        return self::link(
            fn (): string => FragmentResource::getUrl('index'),
            'Fragmente verwalten',
            Heroicon::OutlinedPuzzlePiece,
        );
    }

    /**
     * Whether the tenant only inherits the fragment (branding cascade) instead of
     * owning it — edits to it change every tenant that inherits it.
     */
    public static function isInherited(Model $fragment, ?Tenant $tenant): bool
    {
        return $tenant !== null && (string) $fragment->getAttribute('tenant_id') !== (string) $tenant->getKey();
    }

    /**
     * The tenant a fragment belongs to. An inherited fragment always comes from
     * the branding tenant, which is memoized per request — the lookup only runs
     * for anything else.
     */
    public static function owningTenant(Model $fragment): ?Tenant
    {
        $tenantModel = Cms::tenantModel();
        $brandingTenant = $tenantModel::defaultBrandingTenant();

        if ($brandingTenant instanceof Tenant && (string) $brandingTenant->getKey() === (string) $fragment->getAttribute('tenant_id')) {
            return $brandingTenant;
        }

        $owner = $tenantModel::query()->find($fragment->getAttribute('tenant_id'));

        return $owner instanceof Tenant ? $owner : null;
    }

    private static function userCanAccessTenant(Tenant $tenant): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof HasTenants && $tenant instanceof Model && $user->canAccessTenant($tenant);
    }

    /**
     * @return Link|null
     */
    private static function link(Closure $url, string $label, string|BackedEnum $icon): ?array
    {
        try {
            return ['url' => $url(), 'label' => $label, 'icon' => $icon];
        } catch (Throwable) {
            return null;
        }
    }
}
