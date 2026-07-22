<?php

namespace Mmoollllee\Cms\Support\Media;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;

/**
 * The default per-tenant folder structure of the media library — deliberately
 * flat and context-based (not date- or type-nested):
 *
 *   Branding/    logos, favicon, default OG image        → branding pickers
 *   Seiten/      content-block + hero images and videos  → block/hero/SEO pickers
 *   Dokumente/   PDFs and other downloads                → document pickers
 *
 * Two access modes with different side-effect contracts:
 *
 * - {@see find()} — read-only, request-memoized. This is what picker
 *   `defaultFolder` closures use: they are evaluated on EVERY form render,
 *   so they must never create — otherwise a folder the editor deleted or
 *   renamed would silently reappear on the next page view.
 * - {@see ensure()} — creates on demand (`firstOrCreate`). Used by writing
 *   flows that need the folder to exist (the import command). Not atomic
 *   without a DB unique index, but the memo + single-writer usage keeps the
 *   race window to concurrent first-time imports.
 *
 * Names are app-configurable via {@see Cms::useMediaFolderNames()}.
 */
final class MediaFolders
{
    public const BRANDING = 'branding';

    public const PAGES = 'pages';

    public const DOCUMENTS = 'documents';

    /** @var array<string, MediaLibraryFolder|null> */
    private static array $memo = [];

    public static function branding(?Model $tenant = null): ?MediaLibraryFolder
    {
        return static::ensure(self::BRANDING, $tenant);
    }

    public static function pages(?Model $tenant = null): ?MediaLibraryFolder
    {
        return static::ensure(self::PAGES, $tenant);
    }

    public static function documents(?Model $tenant = null): ?MediaLibraryFolder
    {
        return static::ensure(self::DOCUMENTS, $tenant);
    }

    /**
     * The root folder for a default-folder key IF it exists — no creation,
     * memoized per request. Null when the media library is unavailable, no
     * tenant context exists, or the folder was never created / was deleted.
     */
    public static function find(string $key, ?Model $tenant = null): ?MediaLibraryFolder
    {
        if (! MediaLibrary::enabled()) {
            return null;
        }

        $tenant ??= static::currentTenant();

        if ($tenant === null) {
            return null;
        }

        $memoKey = static::memoKey($key, $tenant);

        if (! array_key_exists($memoKey, self::$memo)) {
            self::$memo[$memoKey] = MediaLibraryFolder::query()
                ->whereNull('parent_id')
                ->where('name', static::name($key))
                ->whereMorphedTo('tenant', $tenant)
                ->first();
        }

        return self::$memo[$memoKey];
    }

    /**
     * The root folder for a default-folder key, created on demand for the
     * given (or current) tenant.
     */
    public static function ensure(string $key, ?Model $tenant = null): ?MediaLibraryFolder
    {
        if (! MediaLibrary::enabled()) {
            return null;
        }

        $tenant ??= static::currentTenant();

        if ($tenant === null) {
            return null;
        }

        $existing = static::find($key, $tenant);

        if ($existing !== null) {
            return $existing;
        }

        $folder = MediaLibraryFolder::query()->firstOrCreate([
            'name' => static::name($key),
            'parent_id' => null,
            'tenant_type' => $tenant->getMorphClass(),
            'tenant_id' => $tenant->getKey(),
        ]);

        return self::$memo[static::memoKey($key, $tenant)] = $folder;
    }

    /**
     * The default folder key for a legacy upload-directory segment
     * (`tenants/{site_key}/{segment}/…`) — the import command and
     * default-folder wiring share this mapping.
     */
    public static function keyForLegacySegment(string $segment): string
    {
        return match ($segment) {
            'branding', 'seo' => self::BRANDING,
            default => self::PAGES,
        };
    }

    /** Reset the request memo (request teardown + tests via Cms::flush()). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    protected static function name(string $key): string
    {
        return Cms::mediaFolderNames()[$key] ?? $key;
    }

    protected static function memoKey(string $key, Model $tenant): string
    {
        return $tenant->getMorphClass().':'.$tenant->getKey().':'.$key;
    }

    protected static function currentTenant(): ?Model
    {
        return Filament::getTenant() ?? app(CurrentTenant::class)->get();
    }
}
