<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

/**
 * Shared icon options and SVG rendering for RichEditor buttons and links.
 *
 * Uses Blade Icons (blade-ui-kit/blade-icons) to resolve SVGs from
 * resources/icons/ via the `svg()` helper with prefix "icon".
 */
final class IconOptions
{
    /**
     * CTA-relevant icon options for Select fields.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'arrow-right' => '→ Arrow Right',
            'arrow-left' => '← Arrow Left',
            'chevron-down' => '↓ Chevron Down',
            'chevron-right' => '› Chevron Right',
            'chevron-left' => '‹ Chevron Left',
            'chevron-up' => '↑ Chevron Up',
        ];
    }

    /**
     * Resolve an icon name to an inline SVG string via Blade Icons.
     *
     * Returns an empty string when the icon name is blank or unknown,
     * so it can be safely concatenated into HTML.
     */
    public static function svg(?string $icon, string $class = 'btn-icon'): string
    {
        if (blank($icon) || ! array_key_exists($icon, self::options())) {
            return '';
        }

        return svg("icon-{$icon}", $class)->toHtml();
    }
}
