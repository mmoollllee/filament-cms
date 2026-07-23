<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mmoollllee\Cms\Concerns\Fragment\ResolvesFragmentWithCascade;
use Mmoollllee\Cms\Concerns\HasDraft;
use Mmoollllee\Cms\Concerns\HasVersions;
use Mmoollllee\Cms\Contracts\Fragment as FragmentContract;

class Fragment extends Model implements FragmentContract
{
    use HasDraft;
    use HasVersions;
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
