{{-- Brand-neutral fallback. Menu entries are rendered from their own
     presentation metadata (target/rel/classes/icon, see
     Menu::linksForLocation) so an editor's choices take effect without the
     consuming app having to fork this view. Icons go through
     <x-site.menu-icon>, which renders nothing for a name no icon set knows —
     this package ships no icon set of its own. --}}
<div
    class="flyout h-screen transition-all"
    x-show="menuOpen"
    x-on:click.outside="closeMenu()"
>
    <div class="flyout-group m-4">
        <div class="flyout-heading sr-only">{{ __('cms::frontend.main_menu') }}</div>
        <div class="flyout-list flex flex-col items-center gap-4 text-center">
            @foreach ($sectionLinks as $sectionLink)
                {{-- Child items (Cms::enableNestedMenus()) follow their parent as
                     `.flyout-btn--child` siblings, so the list keeps one flat
                     rhythm. A top-level entry is active for its whole section
                     (root path), a child only on its own page. --}}
                @foreach ([$sectionLink, ...($sectionLink['children'] ?? [])] as $item)
                    @php
                        $isChild = $loop->index > 0;
                        $target = $item['target'] ?? '_self';
                        $activePath = $isChild ? 'currentNavigationPath()' : 'currentNavigationRootPath()';

                        // noopener is merged in, never replaced: `rel` is a free-text
                        // field an editor can fill with anything, and a link leaving
                        // the site in a new tab must not hand over window.opener.
                        $rel = collect(preg_split('/\s+/', (string) ($item['rel'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
                            ->push('noopener')
                            ->unique()
                            ->implode(' ');
                    @endphp

                    <a
                        href="{{ $item['href'] }}"
                        class="{{ trim('flyout-btn '.($isChild ? 'flyout-btn--child ' : '').($item['classes'] ?? '')) }}"
                        @if ($target !== '_self')
                            target="{{ $target }}"
                            rel="{{ $rel }}"
                        @endif
                        x-bind:class="{ 'is-active': {{ $activePath }} === {{ \Illuminate\Support\Js::from($item['path']) }} }"
                        @if (! $isChild && ($item['children'] ?? []) !== [])
                            {{-- Only one entry may be the current page: on a child's page its
                                 parent is the current section ("true"), not the page. --}}
                            x-bind:aria-current="currentNavigationPath() === {{ \Illuminate\Support\Js::from($item['path']) }} ? 'page' : ({{ $activePath }} === {{ \Illuminate\Support\Js::from($item['path']) }} ? 'true' : 'false')"
                        @else
                            x-bind:aria-current="{{ $activePath }} === {{ \Illuminate\Support\Js::from($item['path']) }} ? 'page' : 'false'"
                        @endif
                    >
                        @if (filled($item['icon'] ?? null))
                            <x-site.menu-icon :name="$item['icon']" class="size-4" />
                        @endif
                        {{ $item['label'] }}
                    </a>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- Ohne gepflegte Social-Links entfällt die Gruppe komplett — der leere
         Hinweis gehört nicht in ein öffentliches Menü. --}}
    @if (count($socialLinks ?? []))
        <div class="flyout-group flyout-group--social mt-8 mx-4 rounded-lg p-4 text-sm font-black">
            <div class="flyout-heading mb-3 text-center text-xs tracking-wider text-muted-text uppercase">{{ __('cms::frontend.social_heading') }}</div>
            <div class="flyout-list flex flex-col items-center gap-4 text-center">
                @foreach ($socialLinks as $socialLink)
                    <a
                        href="{{ $socialLink['url'] }}"
                        class="flyout-btn"
                        target="_blank"
                        rel="noreferrer"
                    >
                        @if (filled($socialLink['icon']))
                            <x-site.menu-icon :name="'icon-'.$socialLink['icon']" class="size-4" />
                        @endif
                        <span>{{ $socialLink['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flyout-group flyout-group--utility mt-8 m-4 text-[0.92rem] font-semibold text-muted-text">
        <div class="flyout-heading sr-only">{{ __('cms::frontend.secondary') }}</div>
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
