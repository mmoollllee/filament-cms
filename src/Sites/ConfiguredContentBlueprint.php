<?php

namespace Mmoollllee\Cms\Sites;

use Illuminate\Support\Str;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Enums\ContentVisibility;

/**
 * Property-based implementation of ContentBlueprint — the standard way to define content types.
 *
 * Subclasses declare their configuration via class properties (like Eloquent models
 * use $table, $fillable, etc.) and override methods for dynamic behavior.
 *
 * Example:
 *   class Blueprint extends ConfiguredContentBlueprint {
 *       protected string $key = 'blog.article';
 *       protected string $label = 'Article';
 *       protected string $defaultTemplate = 'content.article';
 *       protected ?string $urlPathPrefix = '/blog/';
 *   }
 *
 * @see ContentBlueprint — the interface this implements
 */
class ConfiguredContentBlueprint implements ContentBlueprint
{
    protected string $key;

    protected string $label;

    protected string $defaultTemplate;

    protected bool $isRoutable = true;

    protected bool $participatesInOnepager = true;

    protected ?bool $usesOnepagerShell = null;

    protected ContentVisibility $defaultVisibility = ContentVisibility::Public;

    /** @var array<int, string> */
    protected array $allowedParentTypes = [];

    protected ?string $pluralLabel = null;

    protected ?string $navigationLabel = null;

    protected ?string $navigationIcon = null;

    protected ?string $urlPathPrefix = null;

    protected bool $supportsTeasers = false;

    protected bool $hasBuilder = true;

    protected bool $showsPayloadEditor = false;

    /**
     * Whether the catch-all content form offers this type in its "Seiten-Typ"
     * select. The select only appears when a site has MORE than one offered
     * routable type — a site that wants e.g. sections creatable overrides the
     * default.section blueprint with this flag (see the registry's per-site
     * blueprint override).
     */
    protected bool $offeredInTypeSelect = true;

    /** @var array<int, string>|null */
    protected ?array $allowedBlocks = null;

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function defaultTemplate(): string
    {
        return $this->defaultTemplate;
    }

    public function isRoutable(): bool
    {
        return $this->isRoutable;
    }

    public function participatesInOnepager(): bool
    {
        return $this->participatesInOnepager;
    }

    public function usesOnepagerShell(): bool
    {
        return $this->usesOnepagerShell ?? $this->participatesInOnepager;
    }

    public function defaultVisibility(): ContentVisibility
    {
        return $this->defaultVisibility;
    }

    public function allowedParentTypes(): array
    {
        return $this->allowedParentTypes;
    }

    public function offeredInTypeSelect(): bool
    {
        return $this->offeredInTypeSelect;
    }

    public function pluralLabel(): string
    {
        return $this->pluralLabel ?? Str::plural($this->label);
    }

    public function payloadFormComponents(): array
    {
        return [];
    }

    public function generatePath(Content $content): ?string
    {
        if ($this->isRoutable === false) {
            return null;
        }

        // Path already set by the form — respect it
        if (filled($content->path)) {
            return $content->path;
        }

        // Fallback: generate from slug/title
        $slug = $content->slug ?: Str::slug($content->title);

        if ($this->urlPathPrefix !== null && filled($slug)) {
            return rtrim($this->urlPathPrefix, '/').'/'.$slug;
        }

        return filled($slug) ? "/{$slug}" : null;
    }

    public function navigationLabel(): ?string
    {
        return $this->navigationLabel;
    }

    public function navigationIcon(): ?string
    {
        return $this->navigationIcon;
    }

    public function urlPathPrefix(): ?string
    {
        return $this->urlPathPrefix;
    }

    public function supportsTeasers(): bool
    {
        return $this->supportsTeasers;
    }

    public function hasBuilder(): bool
    {
        return $this->hasBuilder;
    }

    public function showsPayloadEditor(): bool
    {
        return $this->showsPayloadEditor;
    }

    public function allowedBlocks(): ?array
    {
        return $this->allowedBlocks;
    }

    /**
     * No blueprint-specific back link by default — the controller falls back to the parent /
     * homepage. Override in a subclass for detail types that link back to their listing.
     *
     * @return array{href: string, label: string}|null
     */
    public function backButton(Content $content): ?array
    {
        return null;
    }
}
