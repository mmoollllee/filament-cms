{{-- Minimal default <x-site.section-header>. Consuming apps override this with
     their own resources/views/components/site/section-header.blade.php (their
     view path takes precedence; this ships as a fallback). --}}
@props([
    'eyebrow' => null,
    'title' => null,
    'heading' => 'h2',
])

@php
    $tag = in_array($heading, ['h1', 'h2', 'h3', 'h4'], true) ? $heading : 'h2';
@endphp

@if (filled($eyebrow))
    <p style="margin:0;text-transform:uppercase;letter-spacing:.08em;font-size:.8rem;font-weight:600;color:var(--primary,#005f4e);">{{ $eyebrow }}</p>
@endif

@if (filled($title))
    <{{ $tag }} style="margin:.2rem 0;">{{ $title }}</{{ $tag }}>
@endif
