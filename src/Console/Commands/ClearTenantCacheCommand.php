<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;

class ClearTenantCacheCommand extends Command
{
    protected $signature = 'cms:clear-tenant-cache
        {--tenant= : Clear cache for a specific tenant ID}
        {--no-warm : Skip re-warming caches after clearing}';

    protected $description = 'Clear and re-warm frontend caches for one or all tenants';

    public function handle(): int
    {
        $tenants = $this->option('tenant')
            ? Cms::tenantModel()::where('id', $this->option('tenant'))->get()
            : Cms::tenantModel()::all();

        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->clearTenantCache($tenant);

            if (! $this->option('no-warm')) {
                $this->warmTenantCache($tenant);
            }
        }

        $this->info("Cache cleared for {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }

    protected function clearTenantCache(Tenant $tenant): void
    {
        $tenantId = $tenant->getKey();

        Cache::forget(CacheKeys::tenantDomain($tenant->primary_domain));

        foreach (array_keys(Cms::menuLocations()) as $location) {
            Cache::forget(CacheKeys::menu($tenantId, $location));
        }

        Cache::forget(CacheKeys::sections($tenantId));
        Cache::forget(CacheKeys::sitemap($tenantId));
        Cache::forget(CacheKeys::redirects($tenantId));
        Cache::forget(CacheKeys::candidates($tenantId));

        // Content path caches
        Cms::contentModel()::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('path')
            ->pluck('path')
            ->each(fn (string $path) => Cache::forget(CacheKeys::content($tenantId, $path)));

        $this->line("  Cleared cache for tenant [{$tenantId}] {$tenant->name}");
    }

    protected function warmTenantCache(Tenant $tenant): void
    {
        $tenantId = $tenant->getKey();

        // Re-warm tenant domain
        Cache::rememberForever(
            CacheKeys::tenantDomain($tenant->primary_domain),
            fn () => $tenant->fresh(),
        );

        // Re-warm menus
        foreach (array_keys(Cms::menuLocations()) as $location) {
            Menu::linksForLocation($location, $tenant);
        }

        // Re-warm the active-redirect map
        app(RedirectResolver::class)->warm($tenant);

        $this->line("  Warmed cache for tenant [{$tenantId}] {$tenant->name}");
    }
}
