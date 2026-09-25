<?php

namespace Mmoollllee\Cms\Support\Tenancy;

use Carbon\CarbonInterface;
use DateTimeZone;
use Exception;
use Filament\Support\Facades\FilamentTimezone;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Providers\BasePanelProvider;

/**
 * The wall-clock timezone a site's editors think in: the tenant's `timezone`
 * column (Europe/Berlin unless set otherwise), falling back to the app timezone.
 *
 * Display only. Timestamps stay UTC in the database and in PHP (`app.timezone`);
 * the panel hands this zone to Filament ({@see BasePanelProvider::configureTimezone()}),
 * whose date pickers convert between the two on every read and write. Without it,
 * an editor typing "bis 18:00" scheduled 18:00 UTC — 20:00 in a German summer.
 */
class TenantTimezone
{
    /** The tenant's zone — a blank or unknown identifier falls back to the app timezone. */
    public static function for(?Tenant $tenant): string
    {
        $timezone = $tenant?->timezone;

        if (is_string($timezone) && $timezone !== '') {
            try {
                return (new DateTimeZone($timezone))->getName();
            } catch (Exception) {
                // A typo in the tenant record must not take the panel down.
            }
        }

        return config('app.timezone');
    }

    public static function current(): string
    {
        return static::for(app(CurrentTenant::class)->get());
    }

    /**
     * A stored moment as the panel shows it: in Filament's timezone — the one the
     * date pickers display — so a sentence never names another time than the field.
     */
    public static function format(?CarbonInterface $moment, string $format = 'd.m.Y H:i'): ?string
    {
        return $moment?->copy()->setTimezone(FilamentTimezone::get())->format($format);
    }
}
