<?php

namespace Mmoollllee\Cms\Support\Versioning;

use Mmoollllee\Cms\Concerns\HasVersions;
use Mmoollllee\Cms\Support\TraitAdoption;

/**
 * Capability checks around the {@see HasVersions} trait — same contract as
 * {@see \Mmoollllee\Cms\Support\Preview\Drafts}: models are app-owned, installs
 * that have not adopted the trait + `versions` table keep working and every
 * versioning UI element hides.
 */
final class Versions
{
    /** Whether the model (class or instance) has adopted {@see HasVersions}. */
    public static function supported(object|string|null $model): bool
    {
        return TraitAdoption::adopted(HasVersions::class, $model);
    }
}
