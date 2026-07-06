<?php

use Mmoollllee\Cms\Support\Routing\PathNormalizer;

beforeEach(function () {
    $this->normalizer = new PathNormalizer;
});

it('normalizes blank and root input to the root path', function () {
    expect($this->normalizer->normalize(null))->toBe('/')
        ->and($this->normalizer->normalize(''))->toBe('/')
        ->and($this->normalizer->normalize('   '))->toBe('/')
        ->and($this->normalizer->normalize('/'))->toBe('/');
});

it('canonicalizes leading/trailing slashes, duplicates, and query strings', function () {
    expect($this->normalizer->normalize('jobs/stellen'))->toBe('/jobs/stellen')
        ->and($this->normalizer->normalize('/jobs/stellen/'))->toBe('/jobs/stellen')
        ->and($this->normalizer->normalize('//jobs//stellen//'))->toBe('/jobs/stellen')
        ->and($this->normalizer->normalize('/jobs/stellen?ref=x'))->toBe('/jobs/stellen')
        ->and($this->normalizer->normalize('/jobs/stellen#anchor'))->toBe('/jobs/stellen');
});

it('normalizeOrNull yields null for blank but a canonical path otherwise', function () {
    expect($this->normalizer->normalizeOrNull(null))->toBeNull()
        ->and($this->normalizer->normalizeOrNull(''))->toBeNull()
        ->and($this->normalizer->normalizeOrNull('/foo/'))->toBe('/foo');
});

it('extracts first and last segments', function () {
    expect($this->normalizer->firstSegment('/a/b/c'))->toBe('a')
        ->and($this->normalizer->lastSegment('/a/b/c'))->toBe('c')
        ->and($this->normalizer->firstSegment('/'))->toBe('')
        ->and($this->normalizer->lastSegment('/'))->toBe('');
});
