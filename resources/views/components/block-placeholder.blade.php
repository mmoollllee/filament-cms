{{-- <x-cms::block-placeholder> — the builder preview of a block whose real
     output cannot (or should not) render in the panel: a live form, a consent-gated
     map, a fragment or a weekly offer edited elsewhere. A dashed card, deliberately
     unlike the frontend, so an editor reads it as "stands in for" rather than as
     the content itself:

         <x-cms::block-placeholder icon="heroicon-o-map-pin" label="Google Maps">
             Karte aus der Adresse in den Seiten-Einstellungen
             <x-slot:actions>
                 <x-cms::manage-link :link="$link" />
             </x-slot:actions>
         </x-cms::block-placeholder>

     The slot is the one-line detail after the label; `actions` holds links to where
     the real thing is managed. Styled in builder.css (plain CSS). --}}
@props([
    'icon' => null,
    'label',
])

<div {{ $attributes->class(['fi-cms-block-placeholder']) }}>
    <div class="fi-cms-block-placeholder-card">
        {{ \Filament\Support\generate_icon_html($icon, attributes: (new \Filament\Support\View\ComponentAttributeBag)->class(['fi-cms-block-placeholder-icon'])) }}

        <span class="fi-cms-block-placeholder-text">
            <span class="fi-cms-block-placeholder-label">{{ $label }}</span>
            @if ($slot->hasActualContent())
                <span class="fi-cms-block-placeholder-arrow" aria-hidden="true">&rarr;</span>
                {{ $slot }}
            @endif
        </span>
    </div>

    {{-- hasActualContent(): a link component that renders nothing (no URL, no
         permission) still leaves whitespace behind, which isNotEmpty() counts. --}}
    @if (isset($actions) && $actions->hasActualContent())
        <div class="fi-cms-manage-links">
            {{ $actions }}
        </div>
    @endif
</div>
