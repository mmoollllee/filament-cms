<?php

use Illuminate\Http\Request;
use Mmoollllee\Cms\Exceptions\NotFoundRenderer;
use Mmoollllee\Cms\Support\Routing\HitRecorder;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Models\Tenant;

/**
 * Render `cms::errors.404` (or a child of it) with the data the NotFoundRenderer passes.
 */
function renderCmsErrorView(string $name): string
{
    return view($name, [
        'tenant' => null,
        'requestedPath' => '/nicht-da',
        'homeUrl' => '/',
        'resolveUrl' => '/_resolve404',
    ])->render();
}

/**
 * Drive the renderer for a given tenant, with the 404-logging side effect stubbed out so
 * the test isolates the view-selection behaviour.
 */
function renderNotFoundFor(Tenant $tenant): Symfony\Component\HttpFoundation\Response
{
    app(CurrentTenant::class)->set($tenant);

    $recorder = Mockery::mock(HitRecorder::class);
    $recorder->shouldReceive('record404')->andReturnNull();
    app()->instance(HitRecorder::class, $recorder);

    return app(NotFoundRenderer::class)->handle(
        Request::create('http://example.test/gibt-es-nicht'),
        new NotFoundHttpException,
    );
}

it('renders the shared branded 404 with unchanged defaults', function () {
    $html = renderCmsErrorView('cms::errors.404');

    expect($html)
        ->toContain('<h1 class="error-title">Seite nicht gefunden</h1>')
        ->toContain('opacity: 0.3')            // .error-code default brightness
        ->toContain('/nicht-da')
        ->toContain('Meinten Sie?')
        ->not->toContain('brightness(0) invert(1)');
});

it('lets a site override only the branded seams via @extends', function () {
    $html = renderCmsErrorView('fixture-site.errors.404');

    // Overridden seams.
    expect($html)
        ->toContain('FIXTURE SITE 404 HEADLINE')
        ->toContain('brightness(0) invert(1)')
        ->toContain('opacity: 0.5');

    // Inherited skeleton stays intact.
    expect($html)
        ->toContain('Meinten Sie?')
        ->toContain('/nicht-da')
        ->toContain('Zurück zur Startseite')
        ->not->toContain('<h1 class="error-title">Seite nicht gefunden</h1>');
});

it('prefers a site-specific errors.404 view when one exists', function () {
    $tenant = Tenant::factory()->create(['site_key' => 'fixture-site']);

    $response = renderNotFoundFor($tenant);

    expect($response->getStatusCode())->toBe(404);
    expect($response->getContent())
        ->toContain('FIXTURE SITE 404 HEADLINE')
        ->not->toContain('<h1 class="error-title">Seite nicht gefunden</h1>');
});

it('falls back to the shared 404 when the site has no custom view', function () {
    $tenant = Tenant::factory()->create(['site_key' => 'no-custom-view']);

    $response = renderNotFoundFor($tenant);

    expect($response->getStatusCode())->toBe(404);
    expect($response->getContent())
        ->toContain('Seite nicht gefunden')
        ->not->toContain('FIXTURE SITE 404 HEADLINE');
});
