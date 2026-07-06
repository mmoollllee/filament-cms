{{-- Demo content template: renders the page title and its builder blocks via
     the package block components (which use the package's default <x-site.*>
     design components). --}}
<article style="display:grid;gap:1.5rem;">
    <h2>{{ $content->title }}</h2>

    @forelse (($content->blocks ?? []) as $block)
        @php
            $type = \Illuminate\Support\Arr::get($block, 'type');
            $data = \Illuminate\Support\Arr::get($block, 'data', []);
        @endphp

        @continue(! view()->exists("blocks::{$type}.{$type}") && ! view()->exists("blocks::{$type}"))

        <x-dynamic-component
            :component="'block::' . $type"
            :data="$data"
            :tenant="$content->tenant"
            :content="$content"
        />
    @empty
        <p>Inhalt folgt.</p>
    @endforelse
</article>
