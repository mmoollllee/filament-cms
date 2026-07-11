@php
    $description = $description ?? null;
    $defaultDomain = $defaultDomain ?? '–';
    $showDefaultPreview = $showDefaultPreview ?? false;
    $previewType = $previewType ?? 'asset';
    $previewUrl = $previewUrl ?? null;
    $previewColor = $previewColor ?? null;
    // Trim so template indentation / stray whitespace can't render as blank lines
    // inside the (whitespace-pre-line) text preview.
    $previewText = filled($previewText ?? null) ? trim((string) $previewText) : null;

    // Only an actually inherited value is worth a box — otherwise "aus {domain}" would
    // show an empty inheritance hint.
    $hasPreview = match ($previewType) {
        'asset' => filled($previewUrl),
        'color' => filled($previewColor),
        'text' => filled($previewText),
        default => false,
    };
@endphp

<div class="space-y-2">
    @if (filled($description))
        <div>{{ $description }}</div>
    @endif

    @if ($showDefaultPreview && $hasPreview)
        <div class="inline-flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-gray-300/80 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">

            @if ($previewType === 'asset')
                <span class="flex h-11 min-w-24 items-center justify-center rounded-lg bg-white px-3 py-2 shadow-sm ring-1 ring-black/5 dark:bg-gray-950/40 dark:ring-white/10">
                    <img src="{{ $previewUrl }}" alt="" class="h-full w-auto max-w-full object-contain">
                </span>
            @elseif ($previewType === 'color')
                <span class="inline-flex items-center gap-2 rounded-full bg-white px-2.5 py-1.5 shadow-sm ring-1 ring-black/5 dark:bg-gray-950/40 dark:ring-white/10">
                    <span class="size-4 rounded-full border border-black/10 dark:border-white/10" style="background-color: {{ $previewColor }}"></span>
                    <span class="font-mono text-[0.72rem]">{{ $previewColor }}</span>
                </span>
            @elseif ($previewType === 'text')
                <span class="max-w-full whitespace-pre-line rounded-lg bg-white px-3 py-1.5 text-left text-[0.72rem] leading-relaxed shadow-sm ring-1 ring-black/5 dark:bg-gray-950/40 dark:ring-white/10">{{ $previewText }}</span>
            @endif

            <span>aus {{ $defaultDomain }}</span>
        </div>
    @endif
</div>
