<?php

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Cms\Contracts\Content as ContentContract;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Seo\SeoHead;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * Pins the SeoHead extension seams: the SeoFields meta.* overrides
 * (seo_title, noindex) are honoured by the shared component, and projects
 * plug page rules / extra JSON-LD in via SeoHead::noindexWhen()/addSchema()
 * instead of copying the view.
 */
beforeEach(fn () => SeoHead::reset());
afterEach(fn () => SeoHead::reset());

function seoHeadExtensionTenant(): Tenant
{
    $tenant = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);

    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

function seoHeadExtensionContent(Tenant $tenant, array $overrides = []): Content
{
    return Content::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'title' => 'Testseite',
        ...$overrides,
    ]);
}

it('prefers the editorial meta.seo_title over the tenant title composition', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant, ['meta' => ['seo_title' => 'Handverlesener Titel']]);

    $rendered = Blade::render('<x-site.seo-head :content="$content" />', ['content' => $content]);

    expect($rendered)
        ->toContain('og:title" content="Handverlesener Titel"')
        ->toContain('twitter:title" content="Handverlesener Titel"')
        ->and(SeoHead::title($content, $tenant))->toBe('Handverlesener Titel');
});

it('falls back to the tenant title composition without a seo_title override', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant);

    expect(SeoHead::title($content, $tenant))->toBe($tenant->frontendTitleFor($content));
});

it('emits a robots noindex directive for the editorial meta.noindex toggle', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant, ['meta' => ['noindex' => true]]);

    $rendered = Blade::render('<x-site.seo-head :content="$content" />', ['content' => $content]);

    expect($rendered)->toContain('<meta name="robots" content="noindex, follow">');
});

it('emits no robots directive by default', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant);

    $rendered = Blade::render('<x-site.seo-head :content="$content" />', ['content' => $content]);

    expect($rendered)->not->toContain('name="robots"');
});

it('lets projects force noindex through a registered rule', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant, ['payload' => ['is_vergeben' => true]]);

    SeoHead::noindexWhen(fn (?object $c): bool => data_get($c?->payload, 'is_vergeben') === true);

    $rendered = Blade::render('<x-site.seo-head :content="$content" />', ['content' => $content]);

    expect($rendered)->toContain('<meta name="robots" content="noindex, follow">');
});

it('renders additional registered json-ld schemas hardened', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant);

    SeoHead::addSchema(fn (?object $c, $t): array => [
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => 'Evil</script><svg onload=alert(1)>',
    ]);
    SeoHead::addSchema(fn (): ?array => null); // skipped provider

    $rendered = Blade::render('<x-site.seo-head :content="$content" />', ['content' => $content]);

    expect($rendered)
        ->toContain('"@type":"LocalBusiness"')
        ->not->toContain('</script><svg')
        ->and(substr_count($rendered, '<script type="application/ld+json">'))->toBe(2); // Organization + LocalBusiness
});

it('lets a blueprint force noindex for its content type', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant, [
        'content_type' => 'jobs.job',
        'payload' => ['is_vergeben' => true],
    ]);
    $open = seoHeadExtensionContent($tenant, [
        'content_type' => 'jobs.job',
        'payload' => ['is_vergeben' => false],
    ]);

    $blueprint = new class extends ConfiguredContentBlueprint
    {
        protected string $key = 'jobs.job';

        protected string $label = 'Job';

        protected string $defaultTemplate = 'content.jobs.detail';

        public function noindex(ContentContract $content): bool
        {
            return data_get($content->payload, 'is_vergeben') === true;
        }
    };

    app()->instance(ContentBlueprintRegistry::class, new class($blueprint) extends ContentBlueprintRegistry
    {
        public function __construct(protected ContentBlueprint $stub)
        {
        }

        public function find(string $key, ?string $siteKey = null): ?ContentBlueprint
        {
            return $key === $this->stub->key() ? $this->stub : null;
        }
    });

    $vergeben = Blade::render('<x-site.seo-head :content="$content" />', ['content' => $content]);
    $offen = Blade::render('<x-site.seo-head :content="$content" />', ['content' => $open]);

    expect($vergeben)->toContain('<meta name="robots" content="noindex, follow">')
        ->and($offen)->not->toContain('name="robots"');
});

it('keeps rules and schemas inert after reset', function () {
    $tenant = seoHeadExtensionTenant();
    $content = seoHeadExtensionContent($tenant);

    SeoHead::noindexWhen(fn (): bool => true);
    SeoHead::addSchema(fn (): array => ['@type' => 'Thing']);
    SeoHead::reset();

    expect(SeoHead::isNoindex($content, $tenant))->toBeFalse()
        ->and(SeoHead::schemas($content, $tenant))->toBe([]);
});
