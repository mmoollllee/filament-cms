<?php

namespace Mmoollllee\Cms\Filament\Resources\LayoutPresets;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\Pages\CreateLayoutPreset;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\Pages\EditLayoutPreset;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\Pages\ListLayoutPresets;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\Schemas\LayoutPresetForm;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\Tables\LayoutPresetsTable;
use Mmoollllee\Cms\Models\LayoutPreset;

class LayoutPresetResource extends Resource
{
    protected static ?string $model = LayoutPreset::class;

    // Presets are a shared pool (tenant_id null = global) managed by superadmins.
    // Filament's tenancy would otherwise scope the table to the current tenant's
    // presets only — hiding every global preset and leaving the list empty.
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static \UnitEnum|string|null $navigationGroup = 'Global';

    public static function form(Schema $schema): Schema
    {
        return LayoutPresetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LayoutPresetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLayoutPresets::route('/'),
            'create' => CreateLayoutPreset::route('/create'),
            'edit' => EditLayoutPreset::route('/{record}/edit'),
        ];
    }

    /**
     * Layout presets are a shared, cross-tenant resource: only superadmins may see and
     * manage them (create/edit/delete). Everyone else merely *uses* them via the
     * {@see LayoutPreset::selectField()} picker on content/block forms.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperadmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Scope to the active tenant's presets plus the shared globals (tenant_id NULL) — see
     * {@see LayoutPreset::scopeAvailableTo()}. Superadmins manage the shared library from
     * whichever tenant panel they are in.
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->availableTo($tenant);
    }
}
