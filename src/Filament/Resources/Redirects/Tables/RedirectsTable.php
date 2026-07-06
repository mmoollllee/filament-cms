<?php

namespace Mmoollllee\Cms\Filament\Resources\Redirects\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Models\Redirect;

class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')
                    ->label('Alter Pfad')
                    ->searchable()
                    ->copyable()
                    ->wrap(),
                TextColumn::make('target')
                    ->label('Ziel')
                    ->state(fn (Redirect $record): string => $record->resolvedTarget() ?? '— (Ziel fehlt)')
                    ->color(fn (Redirect $record): ?string => $record->resolvedTarget() === null ? 'danger' : null)
                    ->wrap(),
                TextColumn::make('status_code')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('origin')
                    ->label('Typ')
                    ->badge()
                    ->formatStateUsing(fn (RedirectOrigin $state): string => $state->label())
                    ->color(fn (RedirectOrigin $state): string => $state->color()),
                ToggleColumn::make('is_active')
                    ->label('Aktiv'),
                TextColumn::make('hits')
                    ->label('Aufrufe')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_hit_at')
                    ->label('Zuletzt')
                    ->dateTime()
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('origin')
                    ->label('Typ')
                    ->options(RedirectOrigin::options()),
                TernaryFilter::make('is_active')
                    ->label('Aktiv'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}
