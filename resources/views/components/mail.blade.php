@props([
    // The tenant whose branding (logo, primary color, contact details) is used.
    // Explicit is preferred: mailables are frequently queued and rendered without a
    // request-scoped tenant. Falls back to the request singleton for synchronous sends.
    'tenant' => null,
    // Optional H1 rendered at the top of the body, in the brand color.
    'heading' => null,
    // Optional hidden preview text (inbox snippet).
    'preheader' => null,
    // Optional <title>; defaults to the heading, then the brand name.
    'title' => null,
    // Optional override for the small print above the copyright line.
    'footnote' => null,
])

@php
    // Anonymous components don't inherit the caller's locals, so resolve the tenant
    // ourselves (explicit prop → request-scoped singleton), mirroring x-site.layout.
    $tenant ??= app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();

    $brandName = $tenant?->displayName() ?: config('app.name', 'CMS');
    // Mail-safe logo: the dedicated raster mail logo or a raster main logo; a blank
    // result falls back to the brand name as text (SVG isn't linked → never a broken img).
    $logoUrl = $tenant?->resolvedMailLogoUrl();
    // resolvedPrimaryColor() already falls back to the engine default; the literal
    // guards the (unexpected) tenant-less render so the template never emits an empty
    // CSS color.
    $primary = $tenant?->resolvedPrimaryColor() ?: '#005f4e';

    $documentTitle = $title ?? $heading ?? $brandName;
    $locale = $tenant?->default_locale ?: app()->getLocale();

    // Footer identity + contact, resolved through the branding cascade. Built as a
    // list of lines so blank fields collapse instead of leaving empty rows.
    $companyName = $tenant?->resolvedSiteSetting('company_name')
        ?: $tenant?->resolvedSiteSetting('legal_name')
        ?: $brandName;

    $addressLine = trim(implode(' ', array_filter([
        $tenant?->resolvedSiteSetting('postal_code'),
        $tenant?->resolvedSiteSetting('city'),
    ])));

    $footerLines = array_values(array_filter([
        $companyName,
        $tenant?->resolvedSiteSetting('street'),
        $addressLine,
    ]));

    $contactEmail = $tenant?->resolvedSiteSetting('contact_email');
    $contactPhone = $tenant?->resolvedSiteSetting('contact_phone');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $documentTitle }}</title>
    <style>
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #eceef1; }
        table { border-collapse: collapse; }
        img { border: 0; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        .cms-mail__body a { color: {{ $primary }}; }
        @media only screen and (max-width: 620px) {
            .cms-mail__container { width: 100% !important; }
            .cms-mail__pad { padding-left: 24px !important; padding-right: 24px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #eceef1;">
    @if (filled($preheader))
        <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all; font-size: 1px; line-height: 1px; color: #eceef1; opacity: 0;">
            {{ $preheader }}
        </div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #eceef1;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" class="cms-mail__container" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden;">
                    {{-- Header: tenant logo, or the brand name when no logo is configured.
                         The logo sits on a white header so any colour/mono mark stays legible. --}}
                    <tr>
                        <td class="cms-mail__pad" align="center" style="padding: 32px 40px 12px 40px;">
                            @if (filled($logoUrl))
                                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" style="display: block; height: auto; max-height: 56px; max-width: 220px; margin: 0 auto;">
                            @else
                                <div style="font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 22px; font-weight: 700; color: {{ $primary }};">{{ $brandName }}</div>
                            @endif
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="cms-mail__pad cms-mail__body" style="padding: 12px 40px 36px 40px; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #1f2933;">
                            @if (filled($heading))
                                <h1 style="margin: 0 0 20px 0; font-size: 22px; line-height: 1.3; font-weight: 700; color: {{ $primary }};">{{ $heading }}</h1>
                            @endif

                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Footer: brand identity + contact, muted --}}
                    <tr>
                        <td class="cms-mail__pad" style="padding: 24px 40px 28px 40px; background-color: #f6f7f9; border-top: 1px solid #e6e8eb; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #6b7280;">
                            @foreach ($footerLines as $line)
                                @if ($loop->first)
                                    <strong style="color: #374151;">{{ $line }}</strong><br>
                                @else
                                    {{ $line }}<br>
                                @endif
                            @endforeach

                            @if (filled($contactEmail) || filled($contactPhone))
                                <span style="display: inline-block; margin-top: 6px;">
                                    @if (filled($contactEmail))
                                        <a href="mailto:{{ $contactEmail }}" style="color: {{ $primary }}; text-decoration: none;">{{ $contactEmail }}</a>
                                    @endif
                                    @if (filled($contactEmail) && filled($contactPhone)) &middot; @endif
                                    @if (filled($contactPhone))
                                        <a href="tel:{{ str_replace(' ', '', $contactPhone) }}" style="color: {{ $primary }}; text-decoration: none;">{{ $contactPhone }}</a>
                                    @endif
                                </span>
                            @endif

                            <div style="margin-top: 14px; color: #9aa2ad;">
                                {{ $footnote ?? 'Diese E-Mail wurde automatisch versendet.' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
