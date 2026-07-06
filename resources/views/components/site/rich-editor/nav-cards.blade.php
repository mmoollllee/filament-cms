@props([
    'cards' => [],
    'preview' => false,
])

@if (count($cards))
    <div class="nav-cards"@if ($preview) style="pointer-events:none;"@endif>
        @foreach ($cards as $card)
            @php
                $label = e($card['label'] ?? '');
                $text = $card['text'] ?? null;
                $cardTag = $preview ? 'div' : 'a';
            @endphp

            <{{ $cardTag }} class="nav-card"
                @unless ($preview)
                    href="{{ e($card['href'] ?? '#') }}"
                    @if (! empty($card['wire_navigate'])) wire:navigate @endif
                    @if (filled($card['rel'] ?? null)) rel="{{ e($card['rel']) }}" @endif
                @endunless
            >
                <span class="nav-card__label">{{ $label }} <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></span>
                @if (filled($text))
                    <span class="nav-card__text">{{ e($text) }}</span>
                @endif
            </{{ $cardTag }}>
        @endforeach
    </div>
@endif
