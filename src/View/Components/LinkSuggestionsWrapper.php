<?php

namespace Mmoollllee\Cms\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Field wrapper for the ContentPathSuggestions inputs.
 *
 * Replaces the defstudio/filament-searchable-input wrapper (same Alpine
 * contract — the vendor `searchableInput` component keeps driving search,
 * keyboard navigation and selection) to render the package's styled two-line
 * suggestion dropdown (title + path).
 *
 * Registered in CmsServiceProvider as `cms-link-suggestions-wrapper` and
 * selected per field via `->fieldWrapperView('cms-link-suggestions-wrapper')`.
 */
class LinkSuggestionsWrapper extends Component
{
    public function render(): View
    {
        return view('cms::filament.forms.link-suggestions-wrapper');
    }
}
