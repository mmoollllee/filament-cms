<?php

namespace Mmoollllee\Cms\Http\Controllers\Frontend;

use Illuminate\Http\Response;

/**
 * Generates a dynamic robots.txt that references the sitemap URL for the current host.
 */
class RobotsController
{
    public function __invoke(): Response
    {
        $sitemapUrl = url('/sitemap.xml');

        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            '',
            'Sitemap: '.$sitemapUrl,
        ]);

        return response($content, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
