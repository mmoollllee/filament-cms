<?php

namespace Mmoollllee\Cms\Support\Assets;

use Filament\Support\Assets\Css;

class ContentVersionedCss extends Css
{
    use HasContentHashVersion;
}
