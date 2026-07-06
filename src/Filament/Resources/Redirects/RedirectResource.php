<?php

namespace Mmoollllee\Cms\Filament\Resources\Redirects;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Mmoollllee\Cms\Filament\Resources\Redirects\Pages\CreateRedirect;
use Mmoollllee\Cms\Filament\Resources\Redirects\Pages\EditRedirect;
use Mmoollllee\Cms\Filament\Resources\Redirects\Pages\ListRedirects;
use Mmoollllee\Cms\Filament\Resources\Redirects\Schemas\RedirectForm;
use Mmoollllee\Cms\Filament\Resources\Redirects\Tables\RedirectsTable;
use Mmoollllee\Cms\Models\Redirect;

class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static \UnitEnum|string|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Weiterleitungen';

    protected static ?string $modelLabel = 'Weiterleitung';

    protected static ?string $pluralModelLabel = 'Weiterleitungen';

    protected static ?int $navigationSort = 10;

    /** Scoped by tenant_id via {@see getEloquentQuery()}, not Filament's ownership relationship. */
    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return RedirectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RedirectsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRedirects::route('/'),
            'create' => CreateRedirect::route('/create'),
            'edit' => EditRedirect::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);

        if ($tenant === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('tenant_id', $tenant->getKey());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()
            ->where('is_active', true)
            ->when(Filament::getTenant(), fn (Builder $q) => $q->where('tenant_id', Filament::getTenant()->getKey()))
            ->count();

        return $count > 0 ? (string) $count : null;
    }
}
