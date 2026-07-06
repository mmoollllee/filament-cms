<?php

namespace Mmoollllee\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Database\Factories\RedirectFactory;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * A tenant-scoped URL redirect (redirection.me-style). Shared infrastructure model owned by
 * the package (like {@see Menu}/{@see LayoutPreset}); the `redirects` table migration lives
 * in the consuming app + is publishable.
 *
 * Soft-deleted so a rejected redirect's `from_path` is remembered forever and never
 * re-suggested. Exactly one of `to_content_id` / `to_url` is the target: `to_content_id` is
 * preferred for internal targets (resolved live via resolvedPath() so it survives path drift).
 *
 * @property RedirectOrigin $origin
 */
class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Set by the runtime auto-resolver while it writes automatic/suggested redirects, so the
     * {@see static::booted()} "human edit promotes automatic → manual" hook does NOT fire for
     * machine writes. Always reset in a finally block by the writer.
     */
    public static bool $autoWriting = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'from_path',
        'to_content_id',
        'to_url',
        'status_code',
        'is_active',
        'origin',
        'hits',
        'last_hit_at',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => RedirectOrigin::class,
            'is_active' => 'boolean',
            'status_code' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RedirectFactory
    {
        return RedirectFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Redirect $redirect): void {
            if ($redirect->tenant_id !== null) {
                return;
            }

            $tenant = app(CurrentTenant::class)->get();

            if ($tenant !== null) {
                $redirect->tenant()->associate($tenant);
            }
        });

        // Canonicalize the stored paths so they compare equal to normalized request paths, and
        // let a content target win when both are set (a redirect has exactly one target).
        static::saving(function (Redirect $redirect): void {
            $normalizer = app(PathNormalizer::class);

            // Cap to the from_path column width (VARCHAR 255). The auto-resolver can persist a
            // request-derived path of any length; without this an over-long path would throw a
            // "data too long" QueryException (a 500 on the public /_resolve404 endpoint).
            $redirect->from_path = Str::limit($normalizer->normalize($redirect->from_path), 255, '');

            if ($redirect->to_content_id !== null) {
                $redirect->to_url = null;
            } elseif (filled($redirect->to_url) && str_starts_with((string) $redirect->to_url, '/')) {
                $redirect->to_url = $normalizer->normalize($redirect->to_url);
            }
        });

        // A human editing an automatic redirect confirms it: drop the "automatic" flag
        // (→ manual) and upgrade the temporary auto status to the permanent one. Lives in the
        // model (not the Filament page) so it also holds for console/tinker edits. Skipped
        // while the auto-resolver itself writes ({@see static::$autoWriting}).
        static::updating(function (Redirect $redirect): void {
            if (static::$autoWriting) {
                return;
            }

            $wasAutomatic = $redirect->getRawOriginal('origin') === RedirectOrigin::Automatic->value;

            if (! $wasAutomatic) {
                return;
            }

            if (! $redirect->isDirty(['from_path', 'to_content_id', 'to_url', 'status_code', 'is_active'])) {
                return;
            }

            $redirect->origin = RedirectOrigin::Manual;

            $autoStatus = (int) config('cms.redirects.auto_status', 302);

            if ((int) $redirect->status_code === $autoStatus && ! $redirect->isDirty('status_code')) {
                $redirect->status_code = (int) config('cms.redirects.confirmed_status', 301);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Cms::tenantModel());
    }

    public function toContent(): BelongsTo
    {
        return $this->belongsTo(Cms::contentModel(), 'to_content_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForTenant(Builder $query, Tenant $tenant): Builder
    {
        return $query->where('tenant_id', $tenant->getKey());
    }

    /**
     * The effective target URL — the linked content's live path, or the stored URL. Returns
     * null when the target is broken (content deleted or now non-routable) so the resolver can
     * fall through to the 404 handler instead of emitting a broken redirect.
     */
    public function resolvedTarget(): ?string
    {
        if ($this->to_content_id !== null) {
            $content = $this->relationLoaded('toContent') ? $this->toContent : $this->toContent()->first();
            $path = $content?->resolvedPath();

            return filled($path) ? $path : null;
        }

        return filled($this->to_url) ? $this->to_url : null;
    }
}
