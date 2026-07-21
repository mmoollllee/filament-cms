<?php

namespace Mmoollllee\Cms\Support\Routing;

use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Mmoollllee\Cms\Support\Preview\PreviewMode;

/**
 * Fuzzy-matches an unresolved path against a tenant's live content to power the "Meinten Sie?"
 * suggestions and the very-high-confidence auto-redirect.
 *
 * Candidates come from a cached list of `{path, title}` built via resolvedPath() over visible
 * content (so blueprint-generated paths are covered, not just the raw `path` column) — the title
 * lets the frontend show a human label alongside each suggested path. Likely matches (same slug
 * or same section) are ranked first and the set is hard-capped; scoring only runs on the (rare)
 * async 404 callback, never on a normal page load.
 */
class PathSuggestionResolver
{
    protected const CANDIDATE_CAP = 500;

    public function __construct(
        protected PathNormalizer $normalizer,
        protected NavigationContextBuilder $navigation,
    ) {}

    /**
     * @return array{best: array{path: string, title: string|null, score: float}|null, suggestions: list<array{path: string, title: string|null, trail: list<string>, score: float}>}
     */
    public function resolve(Tenant $tenant, ?string $path): array
    {
        $requested = $this->normalizer->normalize($path);
        $firstSegment = $this->normalizer->firstSegment($requested);
        $lastSegment = $this->normalizer->lastSegment($requested);

        $candidates = $this->candidates($tenant);

        // Score the whole candidate set, but order it so the most likely matches come first and
        // survive the safety cap: a moved page usually keeps its slug (same last segment) or its
        // section (same first segment). A hard first-segment filter would drop a page moved to a
        // different section even though its slug still matches — the case score() is built for.
        $prioritized = [];
        $rest = [];

        foreach ($candidates as $candidate) {
            if ($candidate['path'] === $requested) {
                continue;
            }

            if ($this->normalizer->lastSegment($candidate['path']) === $lastSegment
                || $this->normalizer->firstSegment($candidate['path']) === $firstSegment
            ) {
                $prioritized[] = $candidate;
            } else {
                $rest[] = $candidate;
            }
        }

        $pool = array_slice([...$prioritized, ...$rest], 0, self::CANDIDATE_CAP);

        $scored = [];

        foreach ($pool as $candidate) {
            $scored[] = [
                'path' => $candidate['path'],
                'title' => $candidate['title'],
                'score' => $this->score($requested, $candidate['path']),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $suggestThreshold = (float) config('cms.redirects.suggest_threshold', 0.5);
        $maxSuggestions = (int) config('cms.redirects.max_suggestions', 3);

        $suggestions = array_slice(
            array_values(array_filter($scored, fn (array $s): bool => $s['score'] >= $suggestThreshold)),
            0,
            $maxSuggestions,
        );

        return [
            'best' => $scored[0] ?? null,
            'suggestions' => $this->attachBreadcrumbTrails($tenant, $suggestions),
        ];
    }

    /**
     * Enrich each suggestion with its breadcrumb label trail (["Products", "Category"]) so the
     * "Meinten Sie?" list can show the page in its hierarchy. Only the ≤N suggestions are enriched
     * (not the whole candidate set), and only on the async 404 callback — one query to load them
     * plus the shared breadcrumb helper's ancestor resolution.
     *
     * @param  list<array{path: string, title: string|null, score: float}>  $suggestions
     * @return list<array{path: string, title: string|null, trail: list<string>, score: float}>
     */
    protected function attachBreadcrumbTrails(Tenant $tenant, array $suggestions): array
    {
        if ($suggestions === []) {
            return [];
        }

        $contentsByPath = Cms::contentModel()::query()
            ->visibleTo($tenant)
            ->whereIn('path', array_column($suggestions, 'path'))
            ->get()
            ->each(fn (Content $content) => $content->setRelation('tenant', $tenant))
            ->keyBy(fn (Content $content): string => $this->normalizer->normalize($content->resolvedPath()));

        return array_map(function (array $suggestion) use ($contentsByPath): array {
            $content = $contentsByPath->get($suggestion['path']);

            $suggestion['trail'] = $content !== null
                ? $this->navigation->breadcrumbLabelsFor($content)
                : array_values(array_filter([$suggestion['title']]));

            return $suggestion;
        }, $suggestions);
    }

    /**
     * Similarity score in [0, 1] between a requested and a candidate path. Weighted blend of
     * last-segment similarity (strongest signal), full-path similarity, and token overlap, with
     * an exact-slug match treated as very high confidence.
     */
    public function score(string $requested, string $candidate): float
    {
        $requested = $this->normalizer->normalize($requested);
        $candidate = $this->normalizer->normalize($candidate);

        if ($requested === $candidate) {
            return 1.0;
        }

        $requestedSegment = $this->normalizer->lastSegment($requested);
        $candidateSegment = $this->normalizer->lastSegment($candidate);

        $score = 0.5 * $this->similarity($requestedSegment, $candidateSegment)
            + 0.3 * $this->similarity($requested, $candidate)
            + 0.2 * $this->tokenOverlap($requested, $candidate);

        // A moved page usually keeps its slug (e.g. /produkte/x → /shop/x): treat an exact
        // last-segment match as a very strong signal.
        if ($requestedSegment !== '' && $requestedSegment === $candidateSegment) {
            $score = max($score, 0.95);
        }

        return round(min($score, 1.0), 4);
    }

    /**
     * @return list<array{path: string, title: string|null}>
     */
    protected function candidates(Tenant $tenant): array
    {
        return Cache::rememberForever(
            CacheKeys::candidates($tenant->getKey()),
            // bypass(): candidates feed guest-facing 404 suggestions AND the
            // auto-created redirect rows — built during a preview request they
            // would leak DRAFT paths/titles (and persist them as redirects).
            fn (): array => app(PreviewMode::class)->bypass(fn (): array => $this->buildCandidates($tenant)),
        );
    }

    /**
     * @return list<array{path: string, title: string|null}>
     */
    protected function buildCandidates(Tenant $tenant): array
    {
        return Cms::contentModel()::query()
            ->visibleTo($tenant)
            ->get()
            ->map(function (Content $content) use ($tenant): ?array {
                $content->setRelation('tenant', $tenant);

                $path = $content->resolvedPath();

                if (blank($path)) {
                    return null;
                }

                $title = trim((string) $content->getAttribute('title'));

                return [
                    'path' => $this->normalizer->normalize($path),
                    'title' => $title !== '' ? $title : null,
                ];
            })
            ->filter()
            ->unique('path')
            ->values()
            ->all();
    }

    protected function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }

        $max = max(strlen($a), strlen($b));

        if ($max === 0) {
            return 0.0;
        }

        // levenshtein() is byte-based and caps at 255 chars per argument.
        $distance = levenshtein(substr($a, 0, 255), substr($b, 0, 255));

        return max(0.0, 1 - ($distance / $max));
    }

    protected function tokenOverlap(string $a, string $b): float
    {
        $tokensA = array_filter(preg_split('#[/\-_]+#', trim($a, '/')) ?: []);
        $tokensB = array_filter(preg_split('#[/\-_]+#', trim($b, '/')) ?: []);

        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $intersection = array_intersect($tokensA, $tokensB);
        $union = array_unique(array_merge($tokensA, $tokensB));

        return count($intersection) / max(count($union), 1);
    }
}
