{{-- Preview: fragment — which fragment the block embeds, what the page shows for it,
     and a link to where the fragment is edited (the fragment list while the slug
     points nowhere). --}}
@php
    use Mmoollllee\Cms\Cms;
    use Mmoollllee\Cms\Filament\Support\ManagementLinks;
    use Mmoollllee\Cms\Support\Content\Blocks\fragment\FragmentBlock;
    use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

    $slug = $slug ?? null;
    $tenant = app(CurrentTenant::class)->get();

    // The record an editor works on (the tenant's own, even while empty) and the
    // one the website shows (own with content, else the inherited one) differ
    // while an own fragment is still empty.
    $fragment = FragmentBlock::findFragment($tenant, $slug);
    $shown = $fragment === null ? null : Cms::fragmentModel()::resolveFragment($tenant, $slug);

    $title = $fragment?->getAttribute('title');
    $isInherited = $fragment !== null && ManagementLinks::isInherited($fragment, $tenant);
    $inheritedFrom = $shown !== null && ManagementLinks::isInherited($shown, $tenant)
        ? (ManagementLinks::owningTenant($shown)?->name ?? 'der Marken-Seite')
        : null;

    $link = $fragment !== null
        ? ManagementLinks::forFragment($fragment, $tenant)
        : ManagementLinks::forFragmentList();
@endphp

<x-cms::block-placeholder icon="heroicon-o-puzzle-piece" label="Fragment">
    @if ($fragment !== null)
        {{ $title ?: $slug }} @if (filled($title))<span class="fi-cms-block-placeholder-muted">({{ $slug }})</span>@endif
        @if ($isInherited)
            — geerbt von {{ $inheritedFrom }}, Änderungen gelten auch dort
        @elseif ($inheritedFrom !== null)
            — noch leer, die Website zeigt solange das Fragment von {{ $inheritedFrom }}
        @elseif ($shown === null)
            — noch leer, auf der Website unsichtbar
        @endif
    @elseif (filled($slug))
        „{{ $slug }}“ nicht gefunden — auf der Website unsichtbar
    @else
        Fragment wählen
    @endif

    <x-slot:actions>
        <x-cms::manage-link :link="$link" />
    </x-slot:actions>
</x-cms::block-placeholder>
