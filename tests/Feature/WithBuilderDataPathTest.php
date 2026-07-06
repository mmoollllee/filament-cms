<?php

use Mmoollllee\Cms\Filament\Concerns\WithBuilderDataPath;

/**
 * The statePath→dataPath mapping used by the paste + transfer builder concerns now lives in a
 * single shared trait. Pin its behaviour so the two concerns can never diverge.
 */
it('strips the leading "data." prefix from a Filament state path', function () {
    $subject = new class
    {
        use WithBuilderDataPath;

        public function map(string $statePath): string
        {
            return $this->toDataPath($statePath);
        }
    };

    expect($subject->map('data.blocks.abc.data.blocks'))->toBe('blocks.abc.data.blocks')
        ->and($subject->map('data.blocks'))->toBe('blocks')
        ->and($subject->map('blocks'))->toBe('blocks')
        ->and($subject->map('data'))->toBe('data');
});
