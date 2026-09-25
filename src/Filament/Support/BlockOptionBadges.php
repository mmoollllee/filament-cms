<?php

namespace Mmoollllee\Cms\Filament\Support;

use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;

/**
 * The badges a builder row shows for a block's option values: what the
 * "Block-Optionen" dialog holds (layout presets, header layout, background image,
 * anchor), readable in the row without opening the dialog. Rendered by the
 * package's builder override next to the row title.
 */
final class BlockOptionBadges
{
    /**
     * @param  array<string, mixed>  $data  the builder item's raw data
     * @return list<array{label: string, title: string}>
     */
    public static function for(array $data): array
    {
        $resolver = app(LayoutPresetResolver::class);
        $badges = [];

        foreach ($resolver->titles(self::presetIds($data['layout_preset_ids'] ?? null)) as $title) {
            $badges[] = ['label' => $title, 'title' => 'Layout'];
        }

        foreach ($resolver->titles(self::presetIds($data['header_preset_ids'] ?? null)) as $title) {
            $badges[] = ['label' => 'Kopf: '.$title, 'title' => 'Header-Layout'];
        }

        if (filled($data['background_image'] ?? null)) {
            $badges[] = ['label' => 'Hintergrundbild', 'title' => 'Hintergrundbild'];
        }

        if (filled($data['anchor_id'] ?? null)) {
            $badges[] = ['label' => '#'.$data['anchor_id'], 'title' => 'Anker-ID'];
        }

        return $badges;
    }

    /**
     * Preset IDs as stored: an int list, occasionally strings (older data), or null.
     *
     * @return array<int, int>
     */
    private static function presetIds(mixed $value): array
    {
        return array_values(array_map('intval', array_filter((array) $value, 'is_numeric')));
    }
}
