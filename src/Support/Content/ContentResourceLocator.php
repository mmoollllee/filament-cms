<?php

namespace Mmoollllee\Cms\Support\Content;

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;

/**
 * Resolves which Filament resource manages a given content type for a tenant's
 * site.
 *
 * A specialized site-extension resource wins (its getContentTypes() names the
 * type); otherwise the panel-wide catch-all resource claims it if it manages the
 * type. Returns null when nothing manages it.
 *
 * Used to build "… verwalten" deep-links (e.g. the listing block's preview action)
 * that jump from a referencing context to the resource that edits that type.
 */
class ContentResourceLocator
{
    public function __construct(
        protected SiteExtensionRegistry $siteExtensionRegistry,
    ) {}

    /**
     * @return class-string<TenantScopedContentResource>|null
     */
    public function resolve(string $contentType, ?Tenant $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }

        foreach ($this->siteExtensionRegistry->forSite($tenant->site_key) as $extension) {
            foreach ($extension->resources() as $resourceClass) {
                if (! is_subclass_of($resourceClass, TenantScopedContentResource::class)) {
                    continue;
                }

                if (in_array($contentType, $resourceClass::getContentTypes(), true)) {
                    return $resourceClass;
                }
            }
        }

        return $this->catchAllResourceFor($contentType);
    }

    /**
     * The catch-all content resource, when it manages the given type. Site
     * extensions may not list it explicitly, so this is the fallback for types
     * handled panel-wide (e.g. pages nested under pages).
     *
     * @return class-string<TenantScopedContentResource>|null
     */
    protected function catchAllResourceFor(string $contentType): ?string
    {
        $resourceClass = Cms::contentResource();

        if (! is_subclass_of($resourceClass, TenantScopedContentResource::class)) {
            return null;
        }

        return in_array($contentType, $resourceClass::getContentTypes(), true) ? $resourceClass : null;
    }
}
