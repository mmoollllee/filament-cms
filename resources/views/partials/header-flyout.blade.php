<div
    class="flyout h-screen transition-all"
    x-show="menuOpen"
    x-on:click.outside="closeMenu()"
>
    <div class="flyout-group m-4">
        <div class="flyout-heading sr-only">Hauptmenü</div>
        <div class="flyout-list flex flex-col items-center gap-4 text-center">
            @foreach ($sectionLinks as $item)
                <a
                    href="{{ $item['href'] }}"
                    class="flyout-btn"
                    x-bind:class="{ 'is-active': currentNavigationRootPath() === {{ \Illuminate\Support\Js::from($item['path']) }} }"
                    x-bind:aria-current="currentNavigationRootPath() === {{ \Illuminate\Support\Js::from($item['path']) }} ? 'page' : 'false'"
                >
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="flyout-group flyout-group--social mt-8 mx-4 rounded-lg p-4 text-sm font-black">
        <div class="flyout-heading mb-3 text-center text-xs tracking-wider text-muted-text uppercase">Folge uns auf Social Media</div>
        <div class="flyout-list flex flex-col items-center gap-4 text-center">
            @forelse ($socialLinks as $socialLink)
                <a
                    href="{{ $socialLink['url'] }}"
                    class="flyout-btn"
                    target="_blank"
                    rel="noreferrer"
                >
                    @if (filled($socialLink['icon']))
                        <x-dynamic-component :component="'icon-'.$socialLink['icon']" class="size-4" />
                    @endif
                    <span>{{ $socialLink['label'] }}</span>
                </a>
            @empty
                <div class="text-center text-sm text-muted-text">Keine Social-Links gepflegt.</div>
            @endforelse
        </div>
    </div>

    <div class="flyout-group flyout-group--utility mt-8 m-4 text-[0.92rem] font-semibold text-muted-text">
        <div class="flyout-heading sr-only">Sekundär</div>
        <div class="flyout-list flex flex-col items-center gap-4 text-center">
            @foreach ($legalLinks as $item)
                <a
                    href="{{ $item['href'] }}"
                    class="flyout-link"
                    x-bind:class="{ 'is-active': currentNavigationPath() === {{ \Illuminate\Support\Js::from($item['path']) }} }"
                    x-bind:aria-current="currentNavigationPath() === {{ \Illuminate\Support\Js::from($item['path']) }} ? 'page' : 'false'"
                >
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </div>
</div>
