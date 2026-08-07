@php
   $link = \Mmoollllee\Cms\Filament\Support\FrontendLinkResolver::forCurrentRoute();
@endphp

{{-- Rendered inside the persistent global-search Livewire component. wire:ignore keeps the
     link (resolved from the current page route on the full server render) stable, so it does
     not change when the search field triggers a Livewire update. The button is omitted when
     the resolver returns no url, i.e. the app has no `content.show` frontend route.

     Styled by the package's precompiled panel stylesheet (resources/css/builder.css)
     — no Tailwind utilities here, so spacing works with and without an app theme. --}}
@if ($link['url'])
   <div wire:ignore class="fi-cms-header-actions">
      <x-filament::button
         :href="$link['url']"
         tag="a"
         target="_blank"
         size="sm"
         icon="heroicon-m-arrow-top-right-on-square"
      >
         <span class="fi-cms-header-actions-label">{{ $link['label'] }}</span>
      </x-filament::button>
   </div>
@endif
