<?php

namespace Mmoollllee\Cms\Support\Routing;

use Illuminate\Support\Str;

/**
 * Decides which 404 paths are scanner/probe noise rather than a broken link worth a redirect.
 *
 * The 404 log is the admin's problem list, so a path that can never be a page of the site —
 * `/.env.prod`, `/wp-includes/wlwmanifest.xml`, `/.well-known/security.txt`, a source map —
 * only buries the entries that matter. {@see HitRecorder} never records such a path, and
 * `cms:prune-not-found-logs` removes the ones logged before a rule covered them, so widening
 * the rules also cleans up history.
 *
 * Two config lists under `cms.redirects`, both compared case-insensitively against the
 * normalized path: `ignore_extensions` (file endings) and `ignore_paths` (`Str::is()`
 * wildcards, where `*` also crosses slashes). On top of them, a request that names itself as
 * its referer is ignored ({@see isSelfReferred()}).
 */
class NotFoundIgnoreList
{
    /**
     * Paths longer than this are never legitimate addresses; they are ignored outright.
     */
    public const MAX_PATH_LENGTH = 2048;

    /**
     * Mirrors config/cms.php, for apps whose published config predates the key.
     *
     * @var list<string>
     */
    public const DEFAULT_EXTENSIONS = [
        'php', 'env', 'asp', 'aspx', 'cgi', 'jsp', 'sql', 'bak',
        'map', 'txt', 'ini', 'log', 'yml', 'yaml', 'sh', 'old', 'swp', 'json',
        'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
    ];

    /**
     * Mirrors config/cms.php, for apps whose published config predates the key.
     *
     * @var list<string>
     */
    public const DEFAULT_PATHS = [
        '*/.*',
        '*.php.*',
        '*.php~',
        '*:*',
        '*/+*',
        '*/%2b*',
        '*/null',
        '*/undefined',
        '/_*',
        '/wp',
        '/wordpress',
        '/wp-content',
        '/wp-content/uploads',
        '*/wp-admin*',
        '*/wp-includes/*',
        '*/wp-content/plugins/*',
        '*/wp-json*',
        '*/wp-login*',
        '/administrator*',
        '/components/com_*',
        '/admin/*',
        '/admin_*',
        '/adminpanel*',
        '/sites/default/*',
        '/magento_version',
        '/sales_order',
        '/key/index',
        '/index/key',
        '/graphql',
        '/rest',
        '/ip',
        '/shell',
        '/webshell',
        '/media/system/*',
        '/cgi-bin/*',
        '/phpmyadmin*',
        '/phpinfo*',
        '/env',
    ];

    public function __construct(protected PathNormalizer $normalizer) {}

    /**
     * @param  string|null  $referer  the request's Referer header, if any
     * @param  string|null  $host  the host the 404 was served on (the tenant's primary domain)
     */
    public function ignores(string $path, ?string $referer = null, ?string $host = null): bool
    {
        if (mb_strlen($path) > self::MAX_PATH_LENGTH) {
            return true;
        }

        $lower = mb_strtolower($path);

        foreach ((array) config('cms.redirects.ignore_extensions', self::DEFAULT_EXTENSIONS) as $extension) {
            if (str_ends_with($lower, '.'.mb_strtolower(ltrim((string) $extension, '.')))) {
                return true;
            }
        }

        foreach ((array) config('cms.redirects.ignore_paths', self::DEFAULT_PATHS) as $pattern) {
            if (Str::is(mb_strtolower((string) $pattern), $lower)) {
                return true;
            }
        }

        return $this->isSelfReferred($lower, $referer, $host);
    }

    /**
     * A page that does not exist cannot have linked to itself. A referer on the same host naming
     * the very path that 404ed is a scanner guessing directories — it asks for "/blog" and claims
     * to come from "/blog/". A foreign host with the same path is a real inbound link and stays.
     */
    protected function isSelfReferred(string $lowerPath, ?string $referer, ?string $host): bool
    {
        if (blank($referer) || blank($host)) {
            return false;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        $refererPath = parse_url($referer, PHP_URL_PATH);

        if (! is_string($refererHost) || ! is_string($refererPath)) {
            return false;
        }

        return $this->bareHost($refererHost) === $this->bareHost($host)
            && mb_strtolower($this->normalizer->normalize($refererPath)) === $lowerPath;
    }

    private function bareHost(string $host): string
    {
        return Str::of($host)->lower()->chopStart('www.')->toString();
    }
}
