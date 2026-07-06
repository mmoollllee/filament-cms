<?php

namespace Mmoollllee\Cms\Fields;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * SEO override fields (stored under `meta.*`). Wired into every content type by
 * default; central additions here (OG image, canonical, JSON-LD, …) reach all
 * projects on update, while each project stays free to re-wire or extend them.
 *
 * The title/description inputs show the frontend fallbacks as placeholders (what the
 * page uses when the override is empty), mirroring the tenant's SEO defaults — so an
 * empty field visibly means "inherit the default".
 *
 */
class SeoFields extends FieldKit
{
    protected function fields(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        return [
            'seo_title' => TextInput::make('meta.seo_title')
                ->label('SEO-Titel')
                ->maxLength(70)
                // Placeholder is the frontend's actual title composition, live from the
                // title field — same source (InheritsBranding) the layout renders with.
                ->placeholder(fn (Get $get): ?string => $tenant?->frontendTitleForValues($get('title'), $get('path')))
                ->helperText('Überschreibt den Standard-Seitentitel in Suchergebnissen. Leer = Standard (siehe Platzhalter).'),
            'seo_description' => Textarea::make('meta.seo_description')
                ->label('SEO-Beschreibung')
                ->rows(2)
                ->maxLength(200)
                // Placeholder mirrors the tenant's default SEO description used on the frontend.
                ->placeholder($tenant?->resolvedDefaultSeoDescription())
                ->columnSpanFull(),
            'noindex' => Toggle::make('meta.noindex')
                ->label('Von Suchmaschinen ausschließen (noindex)'),
        ];
    }
}
