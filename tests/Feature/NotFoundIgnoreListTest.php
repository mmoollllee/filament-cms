<?php

use Mmoollllee\Cms\Models\NotFoundLog;
use Mmoollllee\Cms\Support\Routing\HitRecorder;
use Mmoollllee\Cms\Support\Routing\NotFoundIgnoreList;
use Workbench\App\Models\Tenant;

/*
 * The 404 log is the admin's list of broken links worth a redirect. Scanner probes must stay
 * out of it, but a real old address — including the uploads of a WordPress site the CMS
 * replaced — must still land in it.
 */

it('ignores scanner probes', function (string $path) {
    expect(app(NotFoundIgnoreList::class)->ignores($path))->toBeTrue();
})->with([
    'env file' => '/.env',
    'env variant' => '/.env.prod',
    'git internals' => '/.git/config',
    'well-known file' => '/.well-known/security.txt',
    'nested dotfile' => '/app/.DS_Store',
    'wp-includes under a prefix' => '/blog/wp-includes/wlwmanifest.xml',
    'wp-includes license' => '/wp-includes/ID3/license.txt',
    'wp-admin' => '/wp-admin',
    'wp-json' => '/wp-json/wp/v2/users',
    'wp plugin' => '/wp-content/plugins/revslider/temp',
    'php script' => '/xmlrpc.php',
    'source map' => '/build/assets/app-CXLR9-Ty.css.map',
    'crawler convention file' => '/llms.txt',
    'bare env probe' => '/env',
    'upper case' => '/WP-ADMIN/setup',
    'php variant' => '/shell.php7',
    'php copy' => '/phpinfo.php.save',
    'php backup' => '/phpinfo.php~',
    'secret file' => '/sftp-config.json',
    'phone link resolved as path' => '/mietpark/tel:073449179940',
    'phone number as path' => '/mietpark/%2B49-7344-9179940',
    'data uri as path' => '/data:,',
    'null of a script-running crawler' => '/mietpark/scherenbuehnen/null',
    'framework debug route' => '/_profiler/phpinfo',
    'wordpress directory guess' => '/wordpress',
    'wordpress login' => '/wp-login',
    'joomla admin' => '/administrator/manifests/files/joomla.xml',
    'joomla component' => '/components/com_sppagebuilder/assets/css/sppagebuilder.css',
    'admin subpath' => '/admin/dashboard',
    'shop probe' => '/magento_version',
    'drupal files' => '/sites/default/files',
    'exploit endpoint' => '/graphql',
    'overlong path' => '/'.str_repeat('a', NotFoundIgnoreList::MAX_PATH_LENGTH),
]);

it('keeps paths that can be a broken link worth a redirect', function (string $path) {
    expect(app(NotFoundIgnoreList::class)->ignores($path))->toBeFalse();
})->with([
    'page' => '/leistungen/kanalbau',
    'slug containing wp-' => '/news/swp-partner',
    'wordpress upload' => '/wp-content/uploads/2019/05/flyer.pdf',
    'dot inside a segment' => '/downloads/preisliste.pdf',
    'wordpress theme asset' => '/wp-content/themes/pernes/images/logo-min.svg',
    'old media path' => '/images/2019/05/Hubarbeitsbuehne-scaled.jpg',
    'plus inside a segment' => '/storage/7+DeXn0LcxYKEBpXx9ClGHNihxJzM+KuWeonsX0amYQ=',
    'media conversion' => '/storage/2/conversions/01KMRFACVP8TSGSPMAJNKCRK8F-800.webp',
    'built asset' => '/build/assets/app-C-A4p4cT.js',
    'generic word' => '/admin',
    'old CMS URL routed through index.php' => '/index.php/leistungen.html',
    'login guess' => '/login',
    'career guess' => '/karriere',
]);

it('ignores a request that names itself as its referer on the same host', function (string $referer) {
    expect(app(NotFoundIgnoreList::class)->ignores('/blog', $referer, 'example.test'))->toBeTrue();
})->with([
    'trailing slash' => 'https://example.test/blog/',
    'www and upper case' => 'https://WWW.example.test/Blog',
]);

it('keeps a referer that is a real link', function (?string $referer, ?string $host) {
    expect(app(NotFoundIgnoreList::class)->ignores('/blog', $referer, $host))->toBeFalse();
})->with([
    'another page of the site' => ['https://example.test/news', 'example.test'],
    'same path on another site' => ['https://partner.test/blog', 'example.test'],
    'no referer' => [null, 'example.test'],
    'unknown host' => ['https://example.test/blog', null],
    'not a url' => ['blog', 'example.test'],
]);

it('reads the rules from config', function () {
    config([
        'cms.redirects.ignore_extensions' => [],
        'cms.redirects.ignore_paths' => ['/intern/*'],
    ]);

    $ignoreList = app(NotFoundIgnoreList::class);

    expect($ignoreList->ignores('/intern/probe'))->toBeTrue()
        ->and($ignoreList->ignores('/.env'))->toBeFalse();
});

it('falls back to the default rules when a published config predates them', function () {
    config(['cms.redirects' => ['ignore_extensions' => ['php']]]);

    $ignoreList = app(NotFoundIgnoreList::class);

    expect($ignoreList->ignores('/.env.prod'))->toBeTrue()
        ->and($ignoreList->ignores('/blog/wp-includes/wlwmanifest.xml'))->toBeTrue()
        ->and($ignoreList->ignores('/xmlrpc.php'))->toBeTrue()
        // The app's own extension list stays authoritative.
        ->and($ignoreList->ignores('/llms.txt'))->toBeFalse();
});

it('keeps the fallback rules in step with the shipped config', function () {
    $shipped = require __DIR__.'/../../config/cms.php';

    expect($shipped['redirects']['ignore_extensions'])->toBe(NotFoundIgnoreList::DEFAULT_EXTENSIONS)
        ->and($shipped['redirects']['ignore_paths'])->toBe(NotFoundIgnoreList::DEFAULT_PATHS);
});

it('records a real 404 but not a probe', function () {
    $this->withoutDefer();
    $tenant = Tenant::factory()->create();
    $recorder = app(HitRecorder::class);

    $recorder->record404($tenant, '/.env.prod');
    $recorder->record404($tenant, '/blog/wp-includes/wlwmanifest.xml');
    $recorder->record404($tenant, '/backup', 'https://'.$tenant->primary_domain.'/backup/');
    $recorder->record404($tenant, '/alte-seite');

    expect(NotFoundLog::query()->pluck('path')->all())->toBe(['/alte-seite']);
});

it('prunes rows the ignore list matches regardless of age and hits', function () {
    $tenant = Tenant::factory()->create();
    $kept = NotFoundLog::factory()->for($tenant)->create(['path' => '/alte-seite', 'hits' => 1]);
    NotFoundLog::factory()->for($tenant)->create(['path' => '/.env.live', 'hits' => 40]);
    NotFoundLog::factory()->for($tenant)->create(['path' => '/wp/wp-includes/wlwmanifest.xml', 'hits' => 3]);
    NotFoundLog::factory()->for($tenant)->create([
        'path' => '/old',
        'hits' => 16,
        'last_referer' => 'https://'.$tenant->primary_domain.'/old/',
    ]);

    $this->artisan('cms:prune-not-found-logs')
        ->expectsOutputToContain('Pruned 3 ignored 404 log entr(ies).')
        ->assertSuccessful();

    expect(NotFoundLog::query()->pluck('id')->all())->toBe([$kept->getKey()]);
});
