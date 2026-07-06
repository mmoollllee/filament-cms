<?php

namespace Mmoollllee\Cms\Contracts;

use Mmoollllee\Cms\Sites\SiteExtensionRegistry;

/**
 * Contract for a site extension — a pluggable module that adds content types to the CMS.
 *
 * Each SiteExtension lives in its own directory under the configured sites path
 * (Cms::sitesPath(), e.g. `app/Sites/`) and is auto-discovered by the
 * SiteExtensionRegistry. A tenant's `site_key` determines which extensions are
 * active: the 'default' extension is always loaded, plus the one matching the
 * tenant's key.
 *
 * To create a new site extension:
 * 1. Create `<sites-path>/MyExtension/SiteExtension.php` implementing this interface
 * 2. Return a unique siteKey() (e.g. 'my-extension')
 * 3. Add content types as Blueprint.php in subdirectories (auto-discovered)
 * 4. Add Filament Resources as Resource.php in subdirectories (auto-discovered)
 * 5. Set a tenant's `site_key` to your siteKey() value
 *
 * @see SiteExtensionRegistry — auto-discovers implementations
 * @see ContentBlueprint — defines a single content type
 */
interface SiteExtension
{
    /** Unique identifier for this extension (e.g. 'default', 'blog'). */
    public function siteKey(): string;

    /**
     * Content type definitions provided by this extension.
     *
     * @return array<int, ContentBlueprint>
     */
    public function blueprints(): array;

    /**
     * Filament Resource classes registered by this extension.
     *
     * @return array<int, class-string>
     */
    public function resources(): array;
}
