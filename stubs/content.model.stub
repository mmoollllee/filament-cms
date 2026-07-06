<?php

namespace App\Models;

use Datlechin\FilamentMenuBuilder\Contracts\MenuPanelable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mmoollllee\Cms\Concerns\Content\AssignsCurrentTenant;
use Mmoollllee\Cms\Concerns\Content\ConvertsUploadedVideos;
use Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug;
use Mmoollllee\Cms\Concerns\Content\HasPublishingStatus;
use Mmoollllee\Cms\Concerns\Content\ResolvesLayoutPresets;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Support\AssetUrlResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

class Content extends Model implements \Mmoollllee\Cms\Contracts\Content, MenuPanelable
{
    use AssignsCurrentTenant;     // fills tenant_id from the resolved host tenant
    use ConvertsUploadedVideos;   // dispatches the video re-encode job on save
    use GeneratesPathAndSlug;     // path/slug generation incl. non-routable types
    use HasPublishingStatus;      // status(), resolved_status, scopePublished/VisibleTo/OfType
    use ResolvesLayoutPresets;    // resolvedLayoutPreset() for the frontend

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'parent_id',
        'content_type',
        'template',
        'layout_preset_ids',
        'title',
        'slug',
        'path',
        'visibility',
        'publish_from',
        'publish_until',
        'blocks',
        'payload',
        'references',
        'meta',
        'sort',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ContentVisibility::class,
            'publish_from' => 'datetime',
            'publish_until' => 'datetime',
            'layout_preset_ids' => 'array',
            'blocks' => 'array',
            'payload' => 'array',
            'references' => 'array',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    /**
     * @return Collection<int, self>
     */
    public function visibleChildren(?User $user = null, string|array|null $types = null): Collection
    {
        return self::query()
            ->visibleTo($this->tenant, $user)
            ->where('parent_id', $this->getKey())
            ->when($types !== null, fn (Builder $query): Builder => $query->ofType($types))
            ->orderBy('sort')
            ->orderBy('title')
            ->get();
    }

    /**
     * @return Collection<int, self>
     */
    public function referencedContents(string $referenceKey, string|array|null $types = null, ?User $user = null): Collection
    {
        $referenceIds = array_values(array_filter(
            array_map('intval', (array) data_get($this->references, $referenceKey, [])),
        ));

        if ($referenceIds === []) {
            return new Collection;
        }

        $records = self::query()
            ->visibleTo($this->tenant, $user)
            ->whereKey($referenceIds)
            ->when($types !== null, fn (Builder $query): Builder => $query->ofType($types))
            ->get()
            ->keyBy('id');

        return new Collection(array_values(array_filter(array_map(
            fn (int $id): ?self => $records->get($id),
            $referenceIds,
        ))));
    }

    /**
     * Image path for the page-header background: explicit Titelbild (`hero.image`)
     * if set, otherwise the thumbnail.
     */
    public function heroImagePath(): ?string
    {
        return $this->firstHeroImage('hero.image', 'hero.thumbnail');
    }

    /**
     * Image path for cards / overviews (e.g. category tiles): the
     * dedicated thumbnail, falling back to the Titelbild.
     */
    public function thumbnailPath(): ?string
    {
        return $this->firstHeroImage('hero.thumbnail', 'hero.image');
    }

    /** Public URL for {@see heroImagePath()}, or null when unset. */
    public function heroImageUrl(): ?string
    {
        return AssetUrlResolver::resolve($this->heroImagePath());
    }

    /** Public URL for {@see thumbnailPath()}, or null when unset. */
    public function thumbnailUrl(): ?string
    {
        return AssetUrlResolver::resolve($this->thumbnailPath());
    }

    /**
     * First non-empty hero image among the given payload keys, falling back to
     * the legacy `hero.images` array (pre-migration records).
     */
    private function firstHeroImage(string ...$keys): ?string
    {
        foreach ([...$keys, 'hero.images.0'] as $key) {
            if (filled($value = data_get($this->payload, $key))) {
                return $value;
            }
        }

        return null;
    }

    public function getMenuPanelName(): string
    {
        return 'Inhalte';
    }

    public function getMenuPanelTitleColumn(): string
    {
        return 'title';
    }

    public function getMenuPanelUrlUsing(): callable
    {
        return fn (self $content): string => $content->resolvedPath() ?? '/';
    }

    public function getMenuPanelModifyQueryUsing(): callable
    {
        return function (Builder $query): Builder {
            $tenant = app(CurrentTenant::class)->get();

            return $query
                ->when($tenant !== null, fn (Builder $q): Builder => $q->where('tenant_id', $tenant->getKey()))
                ->whereIn('content_type', ['default.section', 'default.page'])
                ->whereNotNull('path')
                ->orderBy('sort')
                ->orderBy('title');
        };
    }
}
