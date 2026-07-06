<?php

namespace Mmoollllee\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Database\Factories\NotFoundLogFactory;

/**
 * A collected 404 for a tenant — one row per unique requested path, with a hit counter. The
 * passive "problem list" that feeds the admin's redirect creation and gates the runtime
 * auto-resolver (only paths seen repeatedly get an auto/suggested redirect written).
 *
 * Written only after the response is flushed (deferred) and throttled per path, so collecting
 * 404s adds nothing to page load time.
 */
class NotFoundLog extends Model
{
    /** @use HasFactory<NotFoundLogFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'path',
        'hits',
        'last_referer',
        'last_user_agent',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): NotFoundLogFactory
    {
        return NotFoundLogFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Cms::tenantModel());
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
