<?php

namespace Mmoollllee\Cms\Sites\Default;

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\SiteExtension as SiteExtensionContract;
use Mmoollllee\Cms\Filament\Resources\Notices\NoticeResource;
use Mmoollllee\Cms\Sites\Concerns\DiscoversSiteBlueprints;
use Mmoollllee\Cms\Sites\Notice\Blueprint as NoticeBlueprint;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;

/**
 * The always-present "default" site extension shipped by the package.
 *
 * Provides the universal `default.page` / `default.section` content types and points
 * at the app's catch-all content resource (Cms::contentResource()) so those types
 * are editable in the panel — plus the opt-in `default.notice` type with its own
 * resource once an app calls Cms::enableNotices(). The {@see SiteExtensionRegistry}
 * always loads this; an app may still ship its own `App\Sites\Default\SiteExtension`,
 * which overrides this one by site key — with notices enabled, only by extending
 * this class (the registry refuses a replacement that would drop them).
 */
class SiteExtension implements SiteExtensionContract
{
    use DiscoversSiteBlueprints;

    public function siteKey(): string
    {
        return 'default';
    }

    public function resources(): array
    {
        return [
            Cms::contentResource(),
            // Listed here rather than in the panel provider: the catch-all leaves
            // exactly the types that site-extension resources claim to them.
            ...(Cms::hasNotices() ? [NoticeResource::class] : []),
        ];
    }

    /**
     * Opt-in types live outside this directory, where discovery would register
     * them unconditionally.
     *
     * @return array<int, ContentBlueprint>
     */
    protected function inlineBlueprints(): array
    {
        return Cms::hasNotices() ? [app(NoticeBlueprint::class)] : [];
    }
}
