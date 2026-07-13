<?php

namespace Mmoollllee\Cms\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Packs Eloquent models into plain attribute arrays for cache storage and
 * rehydrates them on read.
 *
 * Laravel 13 ships `cache.serializable_classes = false`: cache stores refuse to
 * unserialize PHP objects (gadget-chain hardening for a leaked APP_KEY). Every
 * engine cache payload must therefore stay scalar. The model class is NEVER
 * stored in the payload — callers pass the class on unpack, so a poisoned
 * cache entry cannot choose which class gets instantiated.
 */
class ModelCache
{
    /**
     * Raw (uncasted) attributes, ready for {@see Model::newFromBuilder()}.
     *
     * @return array<string, mixed>|null
     */
    public static function pack(?Model $model): ?array
    {
        return $model?->getAttributes();
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel|null
     */
    public static function unpack(string $class, mixed $attributes): ?Model
    {
        if (! is_array($attributes) || $attributes === []) {
            return null;
        }

        // Via hydrate() (not a bare newFromBuilder()) so the builder stamps the
        // resolved connection name — Model::is() compares it, and the onepager
        // shell matches the current section against findByPath() results with is().
        /** @var TModel|null */
        return $class::hydrate([$attributes])->first();
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return list<array<string, mixed>>
     */
    public static function packMany(Collection $models): array
    {
        return $models->map(fn (Model $model): array => $model->getAttributes())->values()->all();
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return Collection<int, TModel>|null null for a malformed payload — callers re-resolve
     */
    public static function unpackMany(string $class, mixed $items): ?Collection
    {
        if (! is_array($items)) {
            return null;
        }

        return $class::hydrate(array_values(array_filter($items, 'is_array')));
    }
}
