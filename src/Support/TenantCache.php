<?php

namespace Mmoollllee\Cms\Support;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Everything the engine caches per tenant — pages by path, the onepager sections, the
 * sitemap, the 404 suggestion candidates, the redirect map, the menus — under ONE
 * generation per tenant.
 *
 * A write that can change what a visitor sees moves its tenant to a new generation once
 * the write has committed ({@see flushAfterCommit()}); every entry carries the generation
 * it was built under and reads as a miss under any other. That replaces forgetting
 * entries key by key, which went wrong three ways:
 *
 * - it ran inside the save's transaction, so a visitor arriving between the forget and
 *   the commit cached the OLD row again — and kept it until the next save of that page;
 * - it had to name every key a write touches, and missed the ones it could not name: a
 *   page cached under a path other than its stored one, a menu linking a renamed page;
 * - it re-warmed some caches inside the transaction, so a rollback left them holding
 *   rows that were never committed.
 *
 * A reader takes the generation BEFORE it reads the database. One that loads the old row
 * and writes it back after the commit therefore files it under the old generation, where
 * nobody reads it again. Entries keep their key from one generation to the next and are
 * overwritten in place rather than abandoned — a file or database cache store does not
 * fill up with dead keys.
 *
 * Laravel's cache tags would be the stock answer, but the file and database stores these
 * sites run on do not support them. Bound scoped(): the generation is read once per
 * request or job.
 */
class TenantCache
{
    /** @var array<int|string, string> */
    protected array $generations = [];

    /**
     * The tenant's current generation — read from the cache once per request, created
     * on first use.
     */
    public function generation(int|string $tenantId): string
    {
        if (isset($this->generations[$tenantId])) {
            return $this->generations[$tenantId];
        }

        $generation = Cache::get(CacheKeys::generation($tenantId));

        if (! is_string($generation) || $generation === '') {
            $generation = (string) Str::ulid();

            Cache::forever(CacheKeys::generation($tenantId), $generation);
        }

        return $this->generations[$tenantId] = $generation;
    }

    /**
     * The value under $key when it was built under the tenant's current generation,
     * else $default.
     */
    public function get(int|string $tenantId, string $key, mixed $default = null): mixed
    {
        $entry = Cache::get($key);

        return $this->isCurrent($entry, $this->generation($tenantId)) ? $entry['value'] : $default;
    }

    /**
     * Store $value under $key. Pass the generation that was current BEFORE the value was
     * read from the database — that is what files a stale read under a stale generation.
     *
     * @param  DateTimeInterface|DateInterval|int|null  $ttl  null: until the generation moves on
     */
    public function put(int|string $tenantId, string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null, ?string $generation = null): void
    {
        Cache::put($key, [
            'generation' => $generation ?? $this->generation($tenantId),
            'value' => $value,
        ], $ttl);
    }

    /**
     * Laravel's remember(), generation-aware. A closure $ttl receives the value and is only
     * evaluated when something is stored.
     *
     * @param  Closure|DateTimeInterface|DateInterval|int|null  $ttl
     */
    public function remember(int|string $tenantId, string $key, mixed $ttl, Closure $callback): mixed
    {
        $generation = $this->generation($tenantId);
        $entry = Cache::get($key);

        if ($this->isCurrent($entry, $generation)) {
            return $entry['value'];
        }

        $value = $callback();

        $this->put($tenantId, $key, $value, $ttl instanceof Closure ? $ttl($value) : $ttl, $generation);

        return $value;
    }

    /**
     * Move the tenant to a new generation now: everything cached for it so far is a miss.
     */
    public function flush(int|string $tenantId): void
    {
        $generation = (string) Str::ulid();

        Cache::forever(CacheKeys::generation($tenantId), $generation);

        $this->generations[$tenantId] = $generation;
    }

    /**
     * {@see flush()} once $model's transaction has committed: right away when none is open,
     * never when it rolls back.
     */
    public function flushAfterCommit(Model $model, int|string|null $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        // Resolved when the callback runs, not now: the request or job that commits owns it.
        $model->getConnection()->afterCommit(static fn () => app(self::class)->flush($tenantId));
    }

    protected function isCurrent(mixed $entry, string $generation): bool
    {
        return is_array($entry)
            && ($entry['generation'] ?? null) === $generation
            && array_key_exists('value', $entry);
    }
}
