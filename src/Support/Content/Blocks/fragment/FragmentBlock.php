<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\fragment;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Fragment\ResolvesFragmentWithCascade;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;

/**
 * Embeds a reusable fragment by slug — a contact box, the opening hours — so one
 * source can be placed on any page and edited in one place.
 *
 * Opt-in, next to the core blocks, and only meaningful with a fragment model:
 *
 *     Cms::useFragmentModel(Fragment::class);
 *     Cms::registerBlocks([...Cms::defaultBlocks(), FragmentBlock::class]);
 *
 * The editor picks from the tenant's fragments (its own, then the ones the
 * branding cascade would inherit). What is stored stays the slug — the frontend
 * resolves it through the same cascade — so a block keeps working when a
 * fragment is recreated. The row title names the fragment; the preview says what
 * the page will show and links to where the fragment is edited.
 *
 * A fragment that ends up embedding itself (directly, or through another
 * fragment) renders once — {@see renderBlocks()} — instead of recursing until
 * the request dies.
 */
class FragmentBlock extends BaseBuilderBlock
{
    /**
     * Keys of the fragments being rendered right now, outermost first.
     *
     * @var array<int|string, true>
     */
    private static array $rendering = [];

    public function key(): string
    {
        return 'fragment';
    }

    public function make(?Tenant $tenant): Block
    {
        return Block::make('fragment')
            ->icon(Heroicon::OutlinedPuzzlePiece)
            ->label(fn (?array $state, ?string $key): string|Htmlable => static::rowLabel($state, $key, $tenant))
            ->preview('blocks::fragment.preview')
            ->schema([
                ...static::optionHiddenFields(),
                Select::make('slug')
                    ->label('Fragment')
                    ->options(fn (?string $state, ?Model $record): array => static::fragmentOptions($tenant, $state, $record))
                    ->searchable()
                    ->required()
                    ->helperText('Gepflegt unter „Fragmente“ – eine Änderung dort gilt überall, wo das Fragment eingebunden ist.'),
            ]);
    }

    /**
     * The fragment a slug points at, as the panel should show it: the tenant's own
     * record even while empty, else the inherited one. Null without a tenant, a
     * fragment model or a match.
     */
    public static function findFragment(?Tenant $tenant, ?string $slug): ?Model
    {
        $fragmentModel = Cms::fragmentModel();

        if ($tenant === null || $fragmentModel === null || blank($slug)) {
            return null;
        }

        return $fragmentModel::findForEditing($tenant, $slug);
    }

    /**
     * Whether the fragment is already being rendered further up the page — a
     * fragment block reached from inside it would start the loop again.
     */
    public static function isRendering(Model $fragment): bool
    {
        return isset(self::$rendering[$fragment->getKey()]);
    }

    /**
     * The fragment's blocks, rendered like a page's — for the tenant whose page
     * embeds it, not the one it is inherited from. The fragment counts as in
     * progress meanwhile, so a block inside it pointing back at it renders
     * nothing ({@see isRendering()}); the mark goes even when rendering throws.
     *
     * Deliberately without the page's navigation context: its block anchors are
     * indexed by the PAGE's root blocks and would land on the fragment's blocks
     * as duplicate ids.
     */
    public static function renderBlocks(Model $fragment, mixed $content = null, ?Tenant $tenant = null): string
    {
        if (static::isRendering($fragment)) {
            return '';
        }

        $blocks = is_array($fragment->blocks) ? $fragment->blocks : [];

        // The page preloaded only its own block tree — a preset used only inside
        // the fragment (a section grid) would otherwise resolve to no classes.
        app(LayoutPresetResolver::class)->preload($blocks);

        self::$rendering[$fragment->getKey()] = true;

        try {
            return Blade::render('<x-site.content-blocks :blocks="$blocks" :content="$content" :tenant="$tenant" />', [
                'blocks' => $blocks,
                'content' => $content,
                'tenant' => $tenant,
            ]);
        } finally {
            unset(self::$rendering[$fragment->getKey()]);
        }
    }

    /**
     * The block picker gets the plain label; a placed block names its fragment
     * (the slug while it matches none), styled like an inline row title.
     */
    protected static function rowLabel(?array $state, ?string $key, ?Tenant $tenant): string|Htmlable
    {
        $slug = $state['slug'] ?? null;

        if ($state === null || $key === null || blank($slug)) {
            return 'Fragment';
        }

        $name = static::findFragment($tenant, $slug)?->getAttribute('title') ?: $slug;

        return new HtmlString(e($name).' <span class="fi-builder-title-suffix">Fragment</span>');
    }

    /**
     * Slug → "Title (slug)" for the picker: the tenant's own fragments, then the
     * branding tenant's it would inherit — only those with content, exactly what
     * {@see ResolvesFragmentWithCascade::resolveFragment()} serves. The fragment
     * being edited is left out, so it cannot be embedded into itself. A slug that
     * matches no fragment (a typo, a deleted fragment) stays in the list, so the
     * block still says what it points at and the page still saves.
     *
     * @return array<string, string>
     */
    protected static function fragmentOptions(?Tenant $tenant, ?string $current, ?Model $record = null): array
    {
        $fragmentModel = Cms::fragmentModel();
        $options = [];

        if ($tenant !== null && $fragmentModel !== null) {
            foreach ($fragmentModel::allForTenant($tenant) as $slug => $fragment) {
                $options[$slug] = static::optionLabel($fragment, $slug);
            }

            $brandingTenant = Cms::tenantModel()::defaultBrandingTenant();

            if ($brandingTenant instanceof Tenant && ! $brandingTenant->is($tenant)) {
                foreach ($fragmentModel::allForTenant($brandingTenant) as $slug => $fragment) {
                    if ($fragment->hasContent()) {
                        $options[$slug] ??= static::optionLabel($fragment, $slug).' — geerbt';
                    }
                }
            }

            if ($record instanceof $fragmentModel && $record->getAttribute('slug') !== $current) {
                unset($options[$record->getAttribute('slug')]);
            }
        }

        if (filled($current) && ! isset($options[$current])) {
            $options[$current] = "„{$current}“ — nicht gefunden";
        }

        return $options;
    }

    protected static function optionLabel(Model $fragment, string $slug): string
    {
        $title = $fragment->getAttribute('title');

        return filled($title) ? "{$title} ({$slug})" : $slug;
    }
}
