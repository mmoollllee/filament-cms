<?php

namespace Mmoollllee\Cms\Filament\Resources\NotFoundLogs\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Mmoollllee\Cms\Filament\Resources\Redirects\RedirectResource;
use Mmoollllee\Cms\Models\NotFoundLog;

class NotFoundLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('path')
                    ->label('Angeforderter Pfad')
                    ->searchable()
                    ->copyable()
                    ->wrap(),
                TextColumn::make('hits')
                    ->label('Treffer')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Zuletzt')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('last_referer')
                    ->label('Referrer')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('resolved_at')
                    ->label('Gelöst')
                    ->dateTime()
                    ->placeholder('offen')
                    ->toggleable(),
            ])
            ->defaultSort('hits', 'desc')
            ->filters([
                TernaryFilter::make('resolved')
                    ->label('Status')
                    ->placeholder('Alle')
                    ->trueLabel('Gelöst')
                    ->falseLabel('Offen')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('resolved_at'),
                        false: fn ($query) => $query->whereNull('resolved_at'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('create_redirect')
                    ->label('Weiterleitung erstellen')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (NotFoundLog $record): string => RedirectResource::getUrl('create', [
                        'from_path' => $record->path,
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
