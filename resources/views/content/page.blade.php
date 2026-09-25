{{-- Template: content/page — Generic content template (system-wide fallback).
     Renders all builder blocks via the content-blocks component — on the homepage
     below the notice banners (Cms::enableNotices(); nothing without a live one).
     The homepage rather than the shell: in an onepager every section renders this
     template, and the start section is the one a banner belongs to.
     Variables: $content (Content), $navigationContext (array|null) --}}
@if ($content->resolvedPath() === '/')
    <x-cms::notices class="shell" />
@endif

<x-site.content-blocks
    :content="$content"
    :navigation-context="$navigationContext ?? null"
/>
