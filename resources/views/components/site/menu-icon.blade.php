@props(['name' => null])

{{-- Renders a Blade-Icons icon by name, or nothing.

     Menu items carry their icon in a free-text field (the menu builder offers
     no picker), and the icon sets configure no fallback, so `svg()` throws
     SvgNotFound on a typo — on every page that holds the menu, not just the
     one being edited. A navigation icon is decoration; it must not be able to
     take a site down, so an unresolvable name is dropped silently.

     The name is used verbatim, prefix included (`icon-helmet`,
     `heroicon-o-home`): which sets exist and how they are prefixed is the
     consuming app's blade-icons config, not something this package can guess. --}}
@php
    $icon = null;

    if (filled($name)) {
        try {
            $icon = svg($name, $attributes->get('class'))->toHtml();
        } catch (\BladeUI\Icons\Exceptions\SvgNotFound) {
            // Only the unknown-name case is swallowed. A missing icon
            // directory or an unreadable file still surfaces — otherwise every
            // icon on the site vanishes with nothing in the log, and a broken
            // deploy looks exactly like an editor's typo.
        }
    }
@endphp

@if (filled($icon)){!! $icon !!}@endif
