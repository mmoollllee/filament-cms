{{-- Preview: note — what the builder shows for the editor-only placeholder block.
     Deliberately unlike the frontend blocks: a dashed, muted card, so an editor
     can tell at a glance that nothing here reaches the website. --}}
@php
    $title = $title ?? null;
    $renderedContent = \Mmoollllee\Cms\Support\Content\RichText::render($content ?? null);
@endphp

{{-- fi-cms-preview-interactive: links inside this subtree stay clickable inside
     the builder preview (builder.css + the preview click handler in the builder
     override). A note exists to point at where the real records are edited, so a
     dead button in it would defeat the whole block. --}}
<div class="fi-cms-preview-interactive grid gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50/60 p-4 dark:border-gray-600 dark:bg-white/5">
    <p class="m-0 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
        Notiz — erscheint nicht auf der Website
    </p>

    @if (filled($title))
        <p class="m-0 font-semibold text-gray-700 dark:text-gray-200">{{ $title }}</p>
    @endif

    @if (filled($renderedContent))
        {{-- Links are click-dead inside previews via builder.css (.fi-fo-builder-item-preview a). --}}
        <div class="richtext">
            {!! $renderedContent !!}
        </div>
    @endif
</div>
