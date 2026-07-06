<?php

namespace Mmoollllee\Cms\Filament\Resources\LayoutPresets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Models\LayoutPreset;

class LayoutPresetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Titel')
                    ->searchable(),
                TextColumn::make('scope')
                    ->badge(),
                TextColumn::make('type')
                    ->label('Typ')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('classes')
                    ->label('Klassen')
                    ->limit(50)
                    ->tooltip(fn (LayoutPreset $record): string => $record->classes),
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->placeholder('Global'),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->options(LayoutPreset::SCOPES)
                    // scope is a JSON array column — equality (the SelectFilter default)
                    // would never match.
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereJsonContains('scope', $data['value']),
                    )),
                SelectFilter::make('type')
                    ->label('Typ')
                    ->options(fn (): array => LayoutPreset::query()
                        ->whereNotNull('type')
                        ->distinct()
                        ->pluck('type', 'type')
                        ->all()),
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->relationship('tenant', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
