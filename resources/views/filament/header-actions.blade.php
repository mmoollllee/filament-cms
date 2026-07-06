@props([
   'label' => 'Öffnen',
])

{{-- Styled by the package's precompiled panel stylesheet (resources/css/builder.css)
     — no Tailwind utilities here, so spacing works with and without an app theme. --}}
<div class="fi-cms-header-actions">
   <x-filament::button
      href="{{ route('content.show', '/') }}"
      tag="a"
      size="sm"
      icon="heroicon-m-arrow-top-right-on-square"
   >
      @if ($label)
         <span class="fi-cms-header-actions-label">{{ $label }}</span>
      @endif
   </x-filament::button>
</div>
