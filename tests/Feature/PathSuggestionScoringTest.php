<?php

use Mmoollllee\Cms\Support\Routing\PathSuggestionResolver;

beforeEach(function () {
    // Resolved via the container so its collaborators (PathNormalizer, NavigationContextBuilder)
    // are wired up; score() itself is pure and uses only the normalizer.
    $this->resolver = app(PathSuggestionResolver::class);
});

it('scores an identical path as a perfect match', function () {
    expect($this->resolver->score('/a/b', '/a/b'))->toBe(1.0);
});

it('treats a moved page that keeps its slug as very high confidence', function () {
    // Same last segment ("widget") under a different parent → auto-redirect territory.
    expect($this->resolver->score('/products/widget', '/catalog/widget'))
        ->toBeGreaterThanOrEqual(0.92);
});

it('scores a close typo in the medium band and an unrelated path low', function () {
    $typo = $this->resolver->score('/products/widgt', '/products/widget');
    $unrelated = $this->resolver->score('/about', '/products/widget');

    expect($typo)->toBeGreaterThanOrEqual(0.5)
        ->and($typo)->toBeLessThan(0.92)
        ->and($unrelated)->toBeLessThan(0.5);
});
