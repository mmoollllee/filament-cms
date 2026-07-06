{{-- Template: content/page — Generic content template (system-wide fallback).
     Renders all builder blocks via the content-blocks component.
     Variables: $content (Content), $navigationContext (array|null) --}}
<x-site.content-blocks
    :content="$content"
    :navigation-context="$navigationContext ?? null"
/>
