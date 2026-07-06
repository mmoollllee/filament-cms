<?php

namespace Mmoollllee\Cms\Filament\Resources\Redirects\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('origin_label')
                    ->label('Typ')
                    ->content(fn (?Redirect $record): string => $record?->origin?->label() ?? 'Manuell')
                    ->visible(fn (?Redirect $record): bool => $record !== null),

                TextInput::make('from_path')
                    ->label('Alter Pfad')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (): ?string => request()->query('from_path'))
                    ->live(onBlur: true)
                    ->helperText(function (Get $get): string {
                        $tenant = Filament::getTenant();
                        $path = app(PathNormalizer::class)->normalize($get('from_path'));

                        if ($tenant !== null && $path !== '/' && Cms::contentModel()::query()
                            ->where('tenant_id', $tenant->getKey())
                            ->where('path', $path)
                            ->exists()
                        ) {
                            return '⚠︎ Es existiert bereits eine Live-Seite unter diesem Pfad — die Weiterleitung überschattet sie.';
                        }

                        return 'Der alte/zu ersetzende Pfad, z. B. /produkte/altes-geraet';
                    }),

                Select::make('to_content_id')
                    ->label('Ziel: Inhalt')
                    ->relationship(
                        'toContent',
                        'title',
                        fn (Builder $query): Builder => $query
                            ->when(Filament::getTenant(), fn (Builder $q) => $q->where('tenant_id', Filament::getTenant()->getKey()))
                            ->whereNotNull('path'),
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->requiredWithout('to_url')
                    ->helperText('Interne Seite als Ziel (überlebt Pfad-Änderungen).'),

                TextInput::make('to_url')
                    ->label('Ziel: URL / Pfad')
                    ->maxLength(2048)
                    ->requiredWithout('to_content_id')
                    ->helperText('Alternativ ein interner Pfad (/neue-seite) oder eine externe URL (https://…).'),

                Select::make('status_code')
                    ->label('HTTP-Status')
                    ->options([
                        301 => '301 – Dauerhaft',
                        302 => '302 – Temporär',
                        307 => '307 – Temporär (strikt)',
                    ])
                    ->default((int) config('cms.redirects.confirmed_status', 301))
                    ->required(),

                Toggle::make('is_active')
                    ->label('Aktiv')
                    ->default(true),

                Textarea::make('notes')
                    ->label('Notiz')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }
}
