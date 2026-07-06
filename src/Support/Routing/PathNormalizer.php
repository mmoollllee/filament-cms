<?php

namespace Mmoollllee\Cms\Support\Routing;

/**
 * The single source of truth for URL-path normalization across the engine.
 *
 * Consolidates the three previously-duplicated variants (ContentResolver::normalizePath,
 * PathGenerator::normalize, and the trailing-slash middleware) into one canonical form so
 * a stored `path`, a stored redirect `from_path`, and an incoming request path all compare
 * equal regardless of trailing slashes, query strings, or duplicate separators.
 *
 * Canonical form: a single leading slash, no trailing slash (except the root "/"), no query
 * or fragment, and collapsed duplicate slashes.
 */
class PathNormalizer
{
    /**
     * Normalize a path to its canonical form. Blank input yields the root "/".
     */
    public function normalize(?string $path): string
    {
        $path = $this->stripQueryAndFragment((string) $path);
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        $path = '/'.preg_replace('#/+#', '/', ltrim($path, '/'));
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Like {@see normalize()}, but blank input yields null instead of "/". Used where a
     * stored path column is nullable (non-routable content).
     */
    public function normalizeOrNull(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return $this->normalize($path);
    }

    /**
     * The last path segment (slug), e.g. "/a/b/c" → "c". Root yields "".
     */
    public function lastSegment(string $path): string
    {
        $trimmed = trim($this->normalize($path), '/');

        if ($trimmed === '') {
            return '';
        }

        $segments = explode('/', $trimmed);

        return end($segments) ?: '';
    }

    /**
     * The first path segment, e.g. "/a/b/c" → "a". Root yields "".
     */
    public function firstSegment(string $path): string
    {
        $trimmed = trim($this->normalize($path), '/');

        if ($trimmed === '') {
            return '';
        }

        return explode('/', $trimmed)[0];
    }

    private function stripQueryAndFragment(string $path): string
    {
        foreach (['?', '#'] as $separator) {
            $position = strpos($path, $separator);

            if ($position !== false) {
                $path = substr($path, 0, $position);
            }
        }

        return $path;
    }
}
