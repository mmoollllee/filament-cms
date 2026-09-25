{{-- The content form's "Außerdem auf dieser Seite" box: one link per fragment or
     record list the page's template renders besides its blocks
     (Cms::templateEmbeds(), built in TenantScopedContentResource). --}}
<div class="fi-cms-embedded-links">
    @foreach ($links as $link)
        <x-cms::manage-link :link="$link" />
    @endforeach
</div>
