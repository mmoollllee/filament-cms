<?php

use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Pages\Tenancy\EditTenantProfilePage;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\ModelCache;
use Workbench\App\Models\Tenant;

/**
 * Repointing a tenant at another domain from the panel.
 *
 * This is the one field that decides which site answers for a host, so the
 * tests pin all four halves of it: who may touch it, what a pasted address bar
 * value is reduced to, that the forever-cached host lookup lets go of the old
 * domain, and that the panel — which is itself domain-scoped — follows the move
 * instead of stranding the editor on a URL that no longer resolves.
 */
it('lets a superadmin repoint the tenant and normalizes a pasted URL', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $oldDomain = $tenant->primary_domain;

    // Warm the lookup the way ResolveTenantFromHost does, so a missing
    // invalidation shows up as a stale hit rather than as a silent pass.
    Cache::forever(CacheKeys::tenantDomain($oldDomain), ModelCache::pack($tenant));

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['primary_domain' => 'https://Stage.Example.test/pfad/'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->primary_domain)->toBe('stage.example.test')
        ->and(Cache::get(CacheKeys::tenantDomain($oldDomain)))->toBeNull();
});

it('redirects to the new domain after the switch', function () {
    $tenant = actingAsMarketingPanelAdmin();

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['primary_domain' => 'umgezogen.example.test'])
        ->call('save')
        ->assertHasNoFormErrors()
        // The literal host, not the page's own URL expression re-evaluated: a
        // domain-scoped route that started building the host from getRouteKey()
        // would be wrong on both sides of that comparison and still pass.
        ->assertRedirect('http://umgezogen.example.test/panel/profile');
});

it('stays put when the domain did not change', function () {
    actingAsMarketingPanelAdmin();

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['brand_claim' => 'Nur der Claim'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNoRedirect();
});

it('rejects a domain another tenant already answers for', function () {
    $tenant = actingAsMarketingPanelAdmin();

    // A dotted domain, and the assertion names the rule: the second seeded
    // tenant's own 'localhost' would also trip a host-shape rule, so a bare
    // assertHasFormErrors() there passes whether or not `unique` exists at all.
    Tenant::query()->where('site_key', 'acme')->update(['primary_domain' => 'besetzt.example.test']);

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['primary_domain' => 'besetzt.example.test'])
        ->call('save')
        ->assertHasFormErrors(['primary_domain' => 'unique']);

    expect($tenant->refresh()->primary_domain)->not->toBe('besetzt.example.test');
});

it('accepts a single-label host', function () {
    $tenant = actingAsMarketingPanelAdmin();

    // localhost and intranet names are what development and on-premise installs
    // run on. A host-shape rule that demands a dot makes those tenants' settings
    // page unsaveable for good, since the field is required and validated on
    // every save — the demo's own second tenant is seeded on 'localhost'.
    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['primary_domain' => 'intranet'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->primary_domain)->toBe('intranet');
});

it('keeps an unreadable paste in the field instead of blanking it', function () {
    actingAsMarketingPanelAdmin();

    // normalizeDomain() finds no host here. Writing its null into the field
    // would wipe what the editor pasted and report "required" — the raw value
    // stays so validation can name the actual problem.
    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['primary_domain' => 'https://'])
        ->assertSchemaStateSet(['primary_domain' => 'https://'])
        ->call('save')
        ->assertHasFormErrors(['primary_domain']);
});

it('keeps the domain out of a tenant admin\'s reach', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');
    $oldDomain = $tenant->primary_domain;

    // Straight at the Livewire state, i.e. past the form component that
    // canManageDomain() hides — the tenantFields() allow-list is the guard
    // being tested here, not the field's visibility.
    Livewire::test(EditTenantProfilePage::class)
        ->assertSuccessful()
        ->set('data.primary_domain', 'uebernahme.example.test')
        ->call('save');

    expect($tenant->refresh()->primary_domain)->toBe($oldDomain);
});

it('reduces what editors paste to the bare host', function (string $input, ?string $expected) {
    expect(EditTenantProfilePage::normalizeDomain($input))->toBe($expected);
})->with([
    'plain host' => ['example.test', 'example.test'],
    'scheme' => ['https://example.test', 'example.test'],
    'scheme and path' => ['https://example.test/impressum', 'example.test'],
    'trailing slash' => ['https://example.test/', 'example.test'],
    'mixed case' => ['Stage.Example.Test', 'stage.example.test'],
    'port' => ['example.test:8443', 'example.test'],
    'credentials' => ['https://user:pw@example.test/', 'example.test'],
    'surrounding space' => ['  example.test  ', 'example.test'],
    'single label' => ['localhost', 'localhost'],
    'query holding a double slash' => ['www.example.test/de/impressum?a=b//c', 'www.example.test'],
    'nothing host-shaped' => ['   ', null],
    'scheme with no host' => ['https://', null],
    // Not silently repaired into 'http': a one-slash typo is left recognisable
    // so the editor sees their own text in the error.
    'single-slash typo' => ['http:/stage.example.test', 'http:'],
]);
