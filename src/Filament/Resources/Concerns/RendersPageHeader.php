<?php

namespace Mmoollllee\Cms\Filament\Resources\Concerns;

use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Fields\PageHeaderFields;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;

/**
 * Opt-in page header for a content resource.
 *
 * Renders the "Titelbereich" (text left, images right) from the shared
 * {@see PageHeaderFields} kit. Resources without this trait get no page header
 * (the base {@see TenantScopedContentResource}
 * default), keeping the page-header field set a per-project, per-resource decision.
 */
trait RendersPageHeader
{
    protected static function pageHeaderSection(?Tenant $tenant): ?Section
    {
        $directory = BaseBuilderBlock::uploadDirectory($tenant);

        return Section::make('Titelbereich')
            ->description('Kopfbereich der Seite mit Titelbild, Überschrift und Untertitel.')
            ->icon(Heroicon::OutlinedPhoto)
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                // Text (two thirds): Titel + Darstellung share a row, then Untertitel,
                // then the Groß-only Button-Text + Button-Link on one row.
                Grid::make(['default' => 1, 'md' => 2])
                    ->columnSpan(['default' => 1, 'md' => 2])
                    ->schema(
                        PageHeaderFields::make()
                            ->uploadDirectory($directory)
                            ->only('title', 'size', 'subtitle', 'cta_label', 'cta_url')
                            ->toArray()
                    ),
                // Images (one third): stacked uploads, float image only for „Groß".
                Grid::make(1)
                    ->columnSpan(['default' => 1, 'md' => 1])
                    ->schema(
                        PageHeaderFields::make()
                            ->uploadDirectory($directory)
                            ->only('thumbnail', 'image', 'float_image')
                            ->toArray()
                    ),
            ]);
    }
}
