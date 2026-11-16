<?php

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\UploadLimits;

/*
 * Out of the box the media library refuses files over 10 MB and Livewire
 * every temporary upload over 12 MB — a phone video fails both. The CMS sets
 * 128 MB as the default for both, but never overrides a limit the app chose.
 */

it('raises the vendor upload limits to 128 MB by default', function () {
    expect(config('media-library.max_file_size'))->toBe(128 * 1024 * 1024)
        ->and(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:131072'])
        ->and(config('livewire.temporary_file_upload.max_upload_time'))->toBe(UploadLimits::UPLOAD_MINUTES)
        // The picker shows and enforces the library's limit.
        ->and(app(Cms::mediaDriver())->getMaxFileSizeKb())->toBe(131072);
});

it('takes the size from cms.media.max_upload_size', function () {
    config([
        'cms.media.max_upload_size' => 256 * 1024 * 1024,
        'media-library.max_file_size' => UploadLimits::SPATIE_DEFAULT_MAX_SIZE,
        'livewire.temporary_file_upload.rules' => null,
    ]);

    UploadLimits::apply();

    expect(config('media-library.max_file_size'))->toBe(256 * 1024 * 1024)
        ->and(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:262144']);
});

it('keeps limits the app configured itself', function () {
    config([
        'media-library.max_file_size' => 50 * 1024 * 1024,
        'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:51200'],
        'livewire.temporary_file_upload.max_upload_time' => 12,
    ]);

    UploadLimits::apply();

    expect(config('media-library.max_file_size'))->toBe(50 * 1024 * 1024)
        ->and(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:51200'])
        ->and(config('livewire.temporary_file_upload.max_upload_time'))->toBe(12);
});

it('reads the size the way php.ini writes it, and keeps the default for a value it cannot read', function () {
    expect(UploadLimits::bytes('256M'))->toBe(256 * 1024 * 1024)
        ->and(UploadLimits::bytes('1g'))->toBe(1024 * 1024 * 1024)
        ->and(UploadLimits::bytes(' 134217728 '))->toBe(134217728)
        ->and(fn () => UploadLimits::bytes('128MB'))->toThrow(InvalidArgumentException::class);

    config(['cms.media.max_upload_size' => '128MB']);

    expect(UploadLimits::maxSize())->toBe(UploadLimits::DEFAULT_MAX_SIZE);
});

it('counts an upload window the app set itself, from its env too, as decided', function () {
    config(['livewire.temporary_file_upload.max_upload_time' => '5']);

    UploadLimits::apply();

    expect(config('livewire.temporary_file_upload.max_upload_time'))->toBe('5');
});
