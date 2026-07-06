<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mmoollllee\Cms\Concerns\Fragment\ResolvesFragmentWithCascade;

class Fragment extends Model implements \Mmoollllee\Cms\Contracts\Fragment
{
    use ResolvesFragmentWithCascade;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'blocks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
