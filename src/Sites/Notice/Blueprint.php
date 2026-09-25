<?php

namespace Mmoollllee\Cms\Sites\Notice;

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Resources\Notices\NoticeResource;
use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

/**
 * Notice banners ("Hinweise") — opt-in via {@see Cms::enableNotices()}, which
 * registers this type with the default site extension. A rich text with a
 * publishing window: it shows wherever a template places <x-cms::notices /> while
 * the window is open and disappears on its own afterwards — company holidays,
 * public holidays, a delivery round that is cancelled. No page, no path, no
 * builder.
 */
class Blueprint extends ConfiguredContentBlueprint
{
    public const KEY = 'default.notice';

    protected string $key = self::KEY;

    protected string $label = 'Hinweis';

    protected ?string $pluralLabel = 'Hinweise';

    protected ?string $navigationLabel = 'Hinweise';

    protected string $defaultTemplate = 'content.page';

    protected bool $isRoutable = false;

    protected bool $participatesInOnepager = false;

    protected bool $hasBuilder = false;

    // An expired notice is history, not a task on the dashboard.
    protected bool $expiresByDesign = true;

    public function payloadFormComponents(): array
    {
        return NoticeResource::payloadSections();
    }
}
