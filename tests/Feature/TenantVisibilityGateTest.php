<?php

use Illuminate\Http\Request;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Mmoollllee\Cms\Http\Middleware\EnsureTenantIsVisible;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Workbench\App\Models\Tenant;

/**
 * What the members-only gate must let through.
 *
 * The gate runs in the `web` group, so it sees far more than page requests —
 * and the panel is exempt by path only. Livewire posts every panel interaction
 * to its own endpoint, which lives outside that path, so the gate used to close
 * on the one request that cannot possibly be authenticated yet: the login
 * itself. A members-only tenant's panel was unusable as a result, which is
 * exactly the state a site is in while it is being finished with a customer.
 */
function gateFor(Tenant $tenant): EnsureTenantIsVisible
{
    app(CurrentTenant::class)->set($tenant);

    return app(EnsureTenantIsVisible::class);
}

function membersOnlyTenant(): Tenant
{
    return Tenant::factory()->create(['visibility' => TenantVisibility::Members]);
}

it('lets a guest through to the livewire endpoint on a members-only tenant', function () {
    $gate = gateFor(membersOnlyTenant());

    $response = $gate->handle(
        Request::create(Livewire::getUpdateUri(), 'POST'),
        fn (): Response => response('reached'),
    );

    expect($response->getContent())->toBe('reached');
});

it('still blocks a guest from a members-only frontend page', function () {
    $gate = gateFor(membersOnlyTenant());

    expect(fn () => $gate->handle(
        Request::create('/leistungen', 'GET'),
        fn (): Response => response('reached'),
    ))->toThrow(AccessDeniedHttpException::class);
});

it('lets a guest through to the panel itself', function () {
    $gate = gateFor(membersOnlyTenant());

    $response = $gate->handle(
        Request::create('/panel/login', 'GET'),
        fn (): Response => response('reached'),
    );

    expect($response->getContent())->toBe('reached');
});
