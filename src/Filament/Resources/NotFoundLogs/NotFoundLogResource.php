<?php

namespace Mmoollllee\Cms\Filament\Resources\NotFoundLogs;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Filament\Resources\NotFoundLogs\Pages\ListNotFoundLogs;
use Mmoollllee\Cms\Filament\Resources\NotFoundLogs\Tables\NotFoundLogsTable;
use Mmoollllee\Cms\Models\NotFoundLog;

class NotFoundLogResource extends Resource
{
    protected static ?string $model = NotFoundLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static \UnitEnum|string|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = '404-Fehler';

    protected static ?string $modelLabel = '404-Fehler';

    protected static ?string $pluralModelLabel = '404-Fehler';

    protected static ?int $navigationSort = 20;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return NotFoundLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotFoundLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->where('tenant_id', $tenant->getKey());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()
            ->whereNull('resolved_at')
            ->when(Filament::getTenant(), fn (Builder $q) => $q->where('tenant_id', Filament::getTenant()->getKey()))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
