<?php

namespace Mmoollllee\Cms\Models;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Database\Factories\LayoutPresetFactory;

/**
 * Reusable Tailwind layout presets (CSS class sets) selectable on content + blocks.
 * Shared infrastructure model — identical across projects, so owned by the package
 * (the `layout_presets` table migration stays in the consuming app).
 */
class LayoutPreset extends Model
{
    /** @use HasFactory<LayoutPresetFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'scope',
        'type',
        'title',
        'classes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
        ];
    }

    /**
     * The preset scopes the engine asks for (scope → picker label). Single source
     * for the resource form/table AND the selectField() callers: content resources
     * use `content`, section options `section`, child-block options `section-child`,
     * the section-header layout `section-header`, the listing wrapper `listing-wrapper`.
     *
     * @var array<string, string>
     */
    public const SCOPES = [
        'content' => 'Content',
        'section' => 'Sektion',
        'section-child' => 'Sektions-Block',
        'section-header' => 'Sektions-Header',
        'listing-wrapper' => 'Listing-Wrapper',
    ];

    protected static function newFactory(): LayoutPresetFactory
    {
        return LayoutPresetFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Cms::tenantModel());
    }

    // -------------------------------------------------------------------------
    //  Scopes
    // -------------------------------------------------------------------------

    public function scopeAvailableTo(Builder $query, ?Tenant $tenant): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('tenant_id')
            ->when($tenant, fn (Builder $q) => $q->orWhere('tenant_id', $tenant->getKey())));
    }

    // -------------------------------------------------------------------------
    //  Filament Select Field Factory
    // -------------------------------------------------------------------------

    /**
     * Build a Filament Select for choosing layout presets.
     *
     * Used by both builder blocks and content resources (multi-select, layout_preset_ids).
     */
    public static function selectField(string $scope, ?Tenant $tenant = null): Select
    {
        // Deliberately NOT ->searchable() and NOT ->allowHtml(): the preset list is
        // small, and both features route through JS label handling that breaks with
        // grouped options (blank select, raw markup, "e.replace" errors). Plain
        // grouped text labels render reliably everywhere.
        $field = Select::make('layout_preset_ids')
            ->label('Layout')
            ->multiple()
            ->options(fn (): array => static::query()
                ->whereJsonContains('scope', $scope)
                ->availableTo($tenant)
                ->orderBy('type')
                ->orderBy('title')
                ->get()
                ->groupBy(fn (self $p): string => ucfirst($p->type ?? 'Sonstige'))
                ->map(fn ($group) => $group->mapWithKeys(fn (self $p): array => [
                    $p->id => static::formatOption($p),
                ])->all())
                ->all())
            // Selected-value labels resolved FLAT by id. Without this, Filament's
            // default lookup misses the grouped options for preselected values and
            // the select's badge JS crashes on the undefined label
            // ("e.replace is not a function"), leaving the field blank.
            ->getOptionLabelsUsing(fn (array $values): array => static::query()
                ->whereIn('id', $values)
                ->get()
                ->mapWithKeys(fn (self $p): array => [$p->id => static::formatOption($p)])
                ->all());

        // Presets are a shared, superadmin-managed resource: only superadmins may CREATE them
        // (inline quick-create), everyone else can only pick from the existing shared set.
        if (auth()->user()?->isSuperadmin()) {
            $field
                ->createOptionForm([
                    TextInput::make('title')
                        ->label('Titel')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('type')
                        ->label('Typ')
                        ->maxLength(255)
                        ->helperText('Gruppierung im Dropdown (z.B. "Breite", "Spalten").'),
                    TextInput::make('classes')
                        ->label('Tailwind-Klassen')
                        ->maxLength(500)
                        ->helperText('z.B. "col-span-full" oder "md:grid-cols-2 gap-5"'),
                    TextInput::make('scope')
                        ->label('Scope')
                        ->default($scope)
                        ->disabled()
                        ->dehydrated(),
                ])
                // Quick-created presets are shared/global by default (tenant_id null).
                ->createOptionUsing(fn (array $data): int => static::create([
                    ...$data,
                    'scope' => [$scope],
                    'tenant_id' => null,
                ])->getKey());
        }

        return $field;
    }

    /**
     * Format a preset as a compact two-row HTML option label.
     */
    /**
     * Plain-text option label. Deliberately no HTML: allowHtml() labels break the
     * select's JS badge/label handling with grouped options, and the markup would
     * be unstyled without a panel theme anyway. The classes are visible in the
     * LayoutPresets resource (superadmins) and the quick-create form.
     */
    public static function formatOption(self $preset): string
    {
        return $preset->title;
    }
}
