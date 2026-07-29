{{-- The optional Umami block, as one tag.

     Apps that replace <x-site.layout> with their own shell (pernes' standalone
     view) still need this in their <head>, and having them repeat it is how the
     two drifted: the copy there had no Umami::installed() guard, so removing
     filami from composer.json took the whole frontend down at Blade compile
     time instead of degrading.

     x-dynamic-component, not a static tag: a static one would already fail at
     compile time in installs without the package. --}}
@props(['tenant' => null])

@if (\Mmoollllee\Cms\Support\Analytics\Umami::installed())
    <x-dynamic-component :component="'filami::tracking'" :for="$tenant" :attributes="$attributes" />

    {{-- Custom events on top of the tracker: clicks on phone/mail links
         anywhere on the page (including the ones an editor typed into rich
         text, which are obfuscated and carry data-filami-event instead), plus
         the bridge public forms report their conversions through
         ({@see \Mmoollllee\Cms\Support\Livewire\AbstractTenantAwareForm}).
         Inert without the tracker, so there is no second switch to keep in sync. --}}
    <x-dynamic-component :component="'filami::events'" :for="$tenant" />
@endif
