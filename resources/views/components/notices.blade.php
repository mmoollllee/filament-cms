{{-- <x-cms::notices> — the site's notice banners ("Hinweise", opt-in via
     Cms::enableNotices()): every notice whose publishing window is open — in a
     member's preview every notice, unpublished and expired ones included. Renders
     nothing without a notice, so the caller's layout gets no empty row.

     Markup only — the app styles the shared classes `notices` (the stack),
     `notice` (one banner) and `notice-title` in its site CSS; the text carries
     `richtext` like every other rich-text surface. Pass layout classes from the
     template: <x-cms::notices class="shell" />. An app that needs other markup
     overrides this view (resources/views/vendor/cms/components/notices.blade.php)
     and keeps the query: Notices::shown($tenant, request()->user()). --}}
@props(['tenant' => null])

@php
    $notices = \Mmoollllee\Cms\Support\Content\Notices::shown(
        $tenant ?? app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get(),
        request()->user(),
    );
@endphp

@if ($notices->isNotEmpty())
    <div {{ $attributes->class(['notices']) }} role="status">
        @foreach ($notices as $notice)
            <div class="notice">
                <p class="notice-title">{{ $notice->title }}</p>
                <div class="richtext">{!! \Mmoollllee\Cms\Support\Content\RichText::render(data_get($notice->payload, 'content')) !!}</div>
            </div>
        @endforeach
    </div>
@endif
