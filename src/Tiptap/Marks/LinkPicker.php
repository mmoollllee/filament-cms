<?php

namespace Mmoollllee\Cms\Tiptap\Marks;

use Tiptap\Marks\Link;
use Tiptap\Utils\HTML;

/**
 * Custom Link mark that extends the default TipTap Link.
 *
 * Differences from the default Link mark:
 * - No default target="_blank" or rel="noopener noreferrer nofollow"
 * - Supports title (tooltip) + wire:navigate (Livewire SPA navigation) attributes
 * - Supports class attribute for button styling (button classes)
 *
 * The editor-side counterpart is the `link-attributes` TipTap JS extension
 * (declares the same extra attributes on the built-in link mark, so they
 * survive client-side re-editing).
 *
 * @see \Mmoollllee\Cms\Filament\RichEditor\LinkPickerPlugin
 */
class LinkPicker extends Link
{
    public static $name = 'link';

    public function addOptions()
    {
        return [
            ...parent::addOptions(),
            'HTMLAttributes' => [
                'target' => null,
                'rel' => null,
            ],
        ];
    }

    public function addAttributes()
    {
        return [
            ...parent::addAttributes(),
            'title' => [
                'default' => null,
            ],
            'wire:navigate' => [
                'default' => null,
            ],
        ];
    }

    public function renderHTML($mark, $HTMLAttributes = [])
    {
        $isAllowed = $this->options['isAllowedUri']($HTMLAttributes['href'] ?? '');

        if (! $isAllowed) {
            $HTMLAttributes['href'] = '';
        }

        $attributes = HTML::mergeAttributes($this->options['HTMLAttributes'], $HTMLAttributes);

        // Clean up null attributes
        if (isset($mark->attrs)) {
            foreach ((array) $mark->attrs as $key => $value) {
                if ($value === null) {
                    unset($attributes[$key]);
                }
            }
        }

        // Handle wire:navigate as a boolean attribute
        if (isset($attributes['wire:navigate'])) {
            if ($attributes['wire:navigate']) {
                $attributes['wire:navigate'] = true;
            } else {
                unset($attributes['wire:navigate']);
            }
        }

        return [
            'a',
            $attributes,
            0,
        ];
    }
}
