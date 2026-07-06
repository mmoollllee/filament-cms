@props([
    'buttons' => [],
    'alignment' => 'start',
])

@if (count($buttons))
    <div class="btn-group btn-group-{{ e($alignment) }}">
        @foreach ($buttons as $button)
            @php
                $variant = e($button['variant'] ?? 'primary');
                $size = e($button['size'] ?? 'md');
                $classes = "btn btn-{$variant}";
                if ($size !== 'md') {
                    $classes .= " btn-{$size}";
                }
                $iconSvg = \Mmoollllee\Cms\Filament\RichEditor\IconOptions::svg($button['icon'] ?? null);
                $iconPosition = $button['icon_position'] ?? 'after';
            @endphp

            <a class="{{ $classes }}"
                href="{{ e($button['href'] ?? '#') }}"
                @if (! empty($button['wire_navigate'])) wire:navigate @endif
                @if (filled($button['rel'] ?? null)) rel="{{ e($button['rel']) }}" @endif
            >@if ($iconPosition === 'before' && $iconSvg){!! $iconSvg !!}@endif{{ e($button['label'] ?? '') }}@if ($iconPosition === 'after' && $iconSvg){!! $iconSvg !!}@endif</a>
        @endforeach
    </div>
@endif
