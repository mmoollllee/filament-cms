<?php

namespace Mmoollllee\Cms\Filament\Resources\Menus;

use Datlechin\FilamentMenuBuilder\Resources\MenuResource as BaseMenuResource;
use Mmoollllee\Cms\Filament\Resources\Menus\Pages\EditMenu;

/**
 * The menu-builder resource with the engine's own edit page swapped in, so
 * navigation editing takes part in editorial locking like every other content
 * type ({@see EditMenu}). Registered via
 * `FilamentMenuBuilderPlugin::make()->usingResource(...)`; everything else —
 * form, table, labels, navigation — stays the vendor's.
 */
class MenuResource extends BaseMenuResource
{
    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            ...parent::getPages(),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
