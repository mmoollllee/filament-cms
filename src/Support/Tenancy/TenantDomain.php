<?php

namespace Mmoollllee\Cms\Support\Tenancy;

use Illuminate\Support\Str;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;

/**
 * The shape of `tenants.primary_domain`, in one place.
 *
 * {@see ResolveTenantFromHost} matches this
 * column against `$request->getHost()` — a bare, lowercase host. A row that
 * holds anything else (a pasted URL, a stray port, mixed case) matches no
 * request and the site simply 404s, with nothing to trace. So every writer has
 * to agree on the shape, and the rule lives here rather than on whichever
 * screen happened to need it first.
 */
class TenantDomain
{
    /**
     * A bare host: dot-separated labels of letters, digits and inner hyphens.
     *
     * Single-label hosts are valid — `localhost` and intranet names are what
     * development and on-premise installs run on, and a tenant on one has to
     * stay editable. IP literals pass as a side effect of the digit class,
     * which is what an install reached by IP needs.
     */
    public const PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

    /**
     * Reduce an entered value to the bare host, or null when nothing
     * host-shaped is left.
     *
     * Editors paste what the address bar shows them — scheme, path, trailing
     * slash — and shortening that is friendlier than rejecting it. Null lets
     * the caller keep the raw input instead, so a value this cannot make sense
     * of is reported by validation rather than silently replaced.
     */
    public static function normalize(?string $value): ?string
    {
        $host = Str::of((string) $value)->trim()->lower()
            // Only a real `scheme://` is stripped. A single-slash typo
            // (`http:/example.test`) is left alone so the editor sees their own
            // text in the error, rather than the word "http".
            ->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')
            ->before('/')      // path
            ->after('@')       // credentials
            ->replaceMatches('/:\d+$/', '')  // port, digits only
            ->trim('.')
            ->toString();

        return $host === '' ? null : $host;
    }

    /**
     * Validation rules every writer of the column should apply. `unique` stays
     * with the caller — only it knows which record to ignore.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:255', 'regex:'.self::PATTERN];
    }
}
