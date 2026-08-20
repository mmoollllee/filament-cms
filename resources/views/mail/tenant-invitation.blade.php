@php
    // Built here, not inline: an `@if` glued to the preceding word is NOT compiled
    // by Blade (its directive pattern requires a non-word boundary before the @),
    // while the matching `@endif` IS — which leaves an orphan endif and a parse
    // error at RENDER time, i.e. inside the queue worker.
    $invitedByName = $invitedBy?->name ?: $invitedBy?->email;
    $roleSuffix = $role === null ? '' : ' — als '.$role->label();
@endphp

<x-cms::mail
    :tenant="$tenant"
    :heading="'Einladung zu '.$tenant->displayName()"
    :preheader="'Sie wurden eingeladen, '.$tenant->displayName().' zu bearbeiten.'"
>
    <p style="margin: 0 0 16px;">
        @if (filled($invitedByName))
            <strong>{{ $invitedByName }}</strong> hat Sie eingeladen,
        @else
            Sie wurden eingeladen,
        @endif
        die Website <strong>{{ $tenant->displayName() }}</strong> im Redaktionsbereich zu bearbeiten{{ $roleSuffix }}.
    </p>

    <p style="margin: 0 0 24px;">
        <a href="{{ $acceptUrl }}" style="display: inline-block; padding: 12px 20px; border-radius: 8px; background: {{ $tenant->resolvedPrimaryColor() }}; color: #ffffff; text-decoration: none; font-weight: 600;">
            Einladung annehmen
        </a>
    </p>

    @if ($expiresAt)
        <p style="margin: 0 0 12px; font-size: 13px; color: #6b7280;">
            Der Link ist bis zum {{ $expiresAt->translatedFormat('d.m.Y') }} gültig.
        </p>
    @endif

    <p style="margin: 0; font-size: 13px; color: #6b7280; word-break: break-all;">
        Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:<br>
        {{ $acceptUrl }}
    </p>
</x-cms::mail>
