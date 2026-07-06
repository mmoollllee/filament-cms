<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\Contracts;

use Filament\Forms\Components\Builder\Block;
use Mmoollllee\Cms\Contracts\Tenant;

interface BuilderBlock
{
    /** Unique block key (e.g. 'hero', 'landing_hero', 'text'). */
    public function key(): string;

    /** Build the Filament Block instance for the admin form. */
    public function make(?Tenant $tenant): Block;
}
