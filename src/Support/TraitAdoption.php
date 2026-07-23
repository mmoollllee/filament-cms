<?php

namespace Mmoollllee\Cms\Support;

/**
 * Memoized "has this model adopted that trait?" check — the single mechanism
 * behind the {@see \Mmoollllee\Cms\Support\Preview\Drafts} and
 * {@see \Mmoollllee\Cms\Support\Versioning\Versions} capability facades.
 *
 * class_uses_recursive() walks the full parent/trait tree per call and tables
 * ask per row, hence the memo. Trait composition is immutable per process, so
 * the memo is also Octane-safe.
 */
final class TraitAdoption
{
    /** @var array<string, bool> */
    private static array $memo = [];

    public static function adopted(string $trait, object|string|null $model): bool
    {
        if ($model === null) {
            return false;
        }

        $class = is_object($model) ? $model::class : $model;

        return self::$memo[$trait.'@'.$class] ??= in_array($trait, class_uses_recursive($class), true);
    }
}
