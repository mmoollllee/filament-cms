<?php

namespace Mmoollllee\Cms\Filament\Resources\LayoutPresets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Mmoollllee\Cms\Models\LayoutPreset;

class LayoutPresetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Titel')
                    ->required()
                    ->maxLength(255),
                Select::make('scope')
                    ->label('Scope')
                    ->required()
                    ->multiple()
                    ->options(LayoutPreset::SCOPES),
                TextInput::make('type')
                    ->label('Typ')
                    ->maxLength(255)
                    ->helperText('Gruppierung im Dropdown (z.B. "Breite", "Spalten").'),
                TextInput::make('classes')
                    ->label('Tailwind-Klassen')
                    ->maxLength(500)
                    ->helperText('z.B. "col-span-full" oder "md:grid-cols-2 gap-5"'),
                Select::make('tenant_id')
                    ->label('Tenant')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    // Presets are shared/global by default (leave empty). Assign a tenant only
                    // to create a private, tenant-specific override.
                    ->helperText('Leer = global verfügbar für alle Tenants.'),
            ]);
    }
}
