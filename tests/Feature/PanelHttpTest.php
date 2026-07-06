<?php

use Workbench\App\Models\Tenant;

it('resolves a tenant by host and redirects guests to the login page', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'localhost']);

    // Domain-based tenancy: route() builds http://localhost/panel; the request
    // host resolves the tenant via ResolveTenantFromHost, then auth redirects.
    $this->get(route('filament.panel.pages.dashboard', ['tenant' => $tenant]))
        ->assertRedirect();
});

it('returns 404 for a host that matches no tenant', function () {
    $this->get('http://no-such-tenant.test/panel')->assertNotFound();
});
