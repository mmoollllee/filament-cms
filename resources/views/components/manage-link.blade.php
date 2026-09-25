{{-- <x-cms::manage-link> — a link to where content is maintained: "Fragment
     bearbeiten", "Services verwalten", … Used inside builder preview cards and in
     the content form's "Außerdem auf dieser Seite" box.

     Previews are click-dead on purpose (a click opens the inline editor, see the
     builder override), so inside one this needs both halves: builder.css re-enables
     pointer events for .fi-cms-manage-link, and x-on:click.stop keeps the same click
     from also reaching the preview's inline-edit handler. Styled in builder.css
     (plain CSS), so it looks the same in every panel, with or without an app theme.

     Pass a link from ManagementLinks as `:link` (url + label + icon), or `href` +
     `icon` + the label as slot. Renders nothing without a URL. --}}
@props([
    'link' => null,
    'href' => null,
    'icon' => null,
])

@php
    $href ??= $link['url'] ?? null;
    $icon ??= $link['icon'] ?? null;
    $label = $slot->hasActualContent() ? $slot : ($link['label'] ?? null);
@endphp

@if (filled($href))
    {{-- Leaving a form with unsaved changes asks first. On a content or fragment
         edit/create page (ConfirmsLeaving: $wire.draftSavedDataHash) a changed
         form — same hash comparison as the "Entwurf speichern" button — opens its
         confirmLeave action (save first / leave anyway / stay) instead of
         navigating; every other page, a clean form and a modifier click (new tab
         or window — the form stays open) just follow the link. --}}
    <a
        href="{{ $href }}"
        x-on:click.stop="
            if ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey) return;
            if (typeof window.jsMd5 !== 'function' || typeof $wire?.draftSavedDataHash !== 'string') return;
            if ({{ \Mmoollllee\Cms\Filament\Support\UnsavedChanges::pristineJs() }}) return;
            $event.preventDefault();
            $wire.mountAction('confirmLeave', { url: $el.href });
        "
        {{ $attributes->class(['fi-cms-manage-link']) }}
    >
        {{ \Filament\Support\generate_icon_html($icon) }}
        <span>{{ $label }}</span>
    </a>
@endif
