<?php

namespace Mmoollllee\Cms\Support\Mail;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Contracts\Tenant;

/**
 * Resolves a mail-client-safe logo URL for a tenant.
 *
 * SVG is unreliable in e-mail — Gmail and every Outlook drop it (only WebKit clients
 * like Apple Mail render it). So only raster logos are emitted: the tenant's dedicated
 * `mail_logo_path` (a PNG/JPG uploaded for e-mail) if set, else the main logo when it is
 * already a raster format, else null → the mail layout falls back to the brand name as
 * text (never a broken img). An SVG logo is therefore never linked; upload a PNG in the
 * dedicated "E-Mail-Logo" field when the site logo is an SVG.
 *
 * URLs are absolutized against `app.url` because mail clients have no base URL to
 * resolve a root-relative `/storage/...` path against.
 */
class MailLogo
{
    /** Formats that render across all major mail clients. */
    private const RASTER_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    public static function urlFor(?Tenant $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }

        $path = self::sourcePath($tenant);

        if (blank($path)) {
            return null;
        }

        $extension = Str::lower(pathinfo((string) $path, PATHINFO_EXTENSION));

        if (in_array($extension, self::RASTER_EXTENSIONS, true)) {
            return self::absolutize(self::publicUrl((string) $path));
        }

        // SVG or unknown format → no <img>; the layout renders the brand name as text.
        return null;
    }

    /** The dedicated mail logo if set, else the main logo — both branding-inherited. */
    private static function sourcePath(Tenant $tenant): ?string
    {
        $dedicated = method_exists($tenant, 'resolvedMailLogoPath')
            ? $tenant->resolvedMailLogoPath()
            : null;

        if (filled($dedicated)) {
            return $dedicated;
        }

        return method_exists($tenant, 'resolvedMainLogoPath')
            ? $tenant->resolvedMainLogoPath()
            : null;
    }

    private static function publicUrl(string $path): string
    {
        return Storage::disk('public')->url($path);
    }

    /**
     * Make a URL absolute so mail clients (no base URL) can load it. Leaves already
     * absolute URLs untouched.
     */
    private static function absolutize(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        if (Str::startsWith($url, ['http://', 'https://', '//'])) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
