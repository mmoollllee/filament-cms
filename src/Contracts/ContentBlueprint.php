<?php

namespace Mmoollllee\Cms\Contracts;

use Filament\Schemas\Components\Component;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

/**
 * Contract defining a content type within the CMS.
 *
 * Each blueprint describes how a content type behaves: its URL routing,
 * onepager participation, admin form fields, and template mapping.
 *
 * Used by:
 * - ContentResolver — to determine routing and onepager behavior
 * - TemplateResolver — to find the default Blade template
 * - PathGenerator — to compute the URL path for a Content record
 * - the catch-all content resource — to build admin form sections and filter options
 *
 * For most cases, extend ConfiguredContentBlueprint (a property-based builder)
 * instead of implementing this interface directly.
 *
 * @see ConfiguredContentBlueprint — fluent implementation
 * @see SiteExtension::blueprints() — where blueprints are registered
 */
interface ContentBlueprint
{
    /** Dot-notated content type key (e.g. 'default.page', 'blog.article'). */
    public function key(): string;

    /** Human-readable singular label for the admin panel (e.g. 'Seite', 'Artikel'). */
    public function label(): string;

    /** Blade template path relative to the view namespace (e.g. 'content.page'). */
    public function defaultTemplate(): string;

    /** Whether this content type has its own URL (can be visited directly). */
    public function isRoutable(): bool;

    /** Whether the catch-all content form offers this type in its "Seiten-Typ" select. */
    public function offeredInTypeSelect(): bool;

    /** Whether this type appears as a section on the onepager homepage. */
    public function participatesInOnepager(): bool;

    /** Whether this type renders inside the onepager shell (lazy-loaded via AJAX). */
    public function usesOnepagerShell(): bool;

    public function defaultVisibility(): ContentVisibility;

    /**
     * @return array<int, string>
     */
    public function allowedParentTypes(): array;

    /**
     * Human-readable plural label for this content type (e.g. "Services", "Articles").
     *
     * Used by the catch-all resource's edit page to build dynamic "manage
     * children" actions like "Services verwalten" when the current record is a
     * valid parent for content of this type.
     */
    public function pluralLabel(): string;

    /**
     * Filament form components for structured payload editing.
     *
     * When non-empty, these replace the generic KeyValue editor in the content
     * resource with named, typed fields that map to `payload.*` keys.
     *
     * @return array<int, Component>
     */
    public function payloadFormComponents(): array;

    public function generatePath(Content $content): ?string;

    /** Navigation label in the Filament panel. Null means this type is managed by the catch-all "Pages" resource. */
    public function navigationLabel(): ?string;

    /** Heroicon for the Filament navigation entry. */
    public function navigationIcon(): ?string;

    /** URL path prefix shown in the admin slug field (e.g. '/projekte/'). Null = '/'. */
    public function urlPathPrefix(): ?string;

    /** Whether this content type supports teaser mode (dual rendering: teaser on onepager + full subpage). */
    public function supportsTeasers(): bool;

    /** Whether this content type uses the block builder for page content. */
    public function hasBuilder(): bool;

    /**
     * Whether to render the opt-in raw payload editor (a generic KeyValue field for
     * arbitrary `payload.*` keys), shown collapsed at the very end of the form. Off by
     * default — a type must actively enable it. Types with structured
     * payloadFormComponents() should leave this off (those fields are their content).
     */
    public function showsPayloadEditor(): bool;

    /**
     * Builder block keys allowed for this content type. Null means all blocks are available.
     *
     * @return array<int, string>|null
     */
    public function allowedBlocks(): ?array;

    /**
     * A blueprint-specific "back" link for the standalone page layout, or null to use the
     * controller's generic default (up to the parent, else to the homepage). Override in a
     * blueprint to send a detail page back to its listing (e.g. an article → "/blog").
     *
     * @return array{href: string, label: string}|null
     */
    public function backButton(Content $content): ?array;
}
