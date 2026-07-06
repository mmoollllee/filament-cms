<?php

use Mmoollllee\Cms\Support\AssetUrlResolver;

it('resolves asset paths from strings, urls, and FileUpload arrays', function () {
    expect(AssetUrlResolver::resolve(null))->toBeNull();
    expect(AssetUrlResolver::resolve(''))->toBeNull();

    // relative storage path → public disk URL
    expect(AssetUrlResolver::resolve('2019/06/x.jpg'))->toBe('/storage/2019/06/x.jpg');

    // already absolute / root-relative → unchanged
    expect(AssetUrlResolver::resolve('/storage/x.jpg'))->toBe('/storage/x.jpg');
    expect(AssetUrlResolver::resolve('https://example.com/x.jpg'))->toBe('https://example.com/x.jpg');

    // Filament FileUpload keeps its state as an array — the case that caused the
    // "array given" TypeError in block previews. First element is used.
    expect(AssetUrlResolver::resolve(['2019/06/x.jpg']))->toBe('/storage/2019/06/x.jpg');
    expect(AssetUrlResolver::resolve(['uuid-key' => '2019/06/x.jpg']))->toBe('/storage/2019/06/x.jpg');
    expect(AssetUrlResolver::resolve([]))->toBeNull();
});
