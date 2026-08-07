<?php

namespace Mmoollllee\Cms\Filament\Resources\Menus\Pages;

use Datlechin\FilamentMenuBuilder\Resources\MenuResource\Pages\EditMenu as BaseEditMenu;
use Mmoollllee\Cms\Filament\Concerns\LocksRecords;

/**
 * Menu editing with editorial locking ({@see LocksRecords}).
 *
 * Note the granularity: the lock is held on the MENU, while its items are
 * written by the builder's own Livewire panels. That is exactly what the
 * blocking modal is for — a second editor never reaches those panels, because
 * the modal covers the page before they can interact with it.
 */
class EditMenu extends BaseEditMenu
{
    use LocksRecords;
}
