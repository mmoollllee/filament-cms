{{-- Block: fragment — a reusable fragment (by slug) rendered inline, resolved through
     the branding cascade; nothing when it is missing or empty, and nothing for a
     fragment that is already being rendered further up (a fragment embedding
     itself, directly or through another one, would otherwise recurse forever). --}}
@props([
    'data' => [],
    'tenant' => null,
    'content' => null,
    'anchorId' => null,
    'navigationContext' => null,
    'layoutPreset' => '',
])

@php
    use Mmoollllee\Cms\Support\Content\Blocks\fragment\FragmentBlock;

    $slug = $data['slug'] ?? null;
    $tenant = $tenant ?? app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();
    $fragmentModel = \Mmoollllee\Cms\Cms::fragmentModel();

    $fragment = (filled($slug) && $tenant !== null && $fragmentModel !== null)
        ? $fragmentModel::resolveFragment($tenant, $slug)
        : null;
@endphp

@if ($fragment?->hasContent() && ! FragmentBlock::isRendering($fragment))
    <div {{ $attributes->class(['anim']) }} @if (filled($anchorId)) id="{{ $anchorId }}" @endif>
        {!! FragmentBlock::renderBlocks($fragment, $content, $tenant) !!}
    </div>
@endif
