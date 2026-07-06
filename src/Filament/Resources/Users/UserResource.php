<?php

namespace Mmoollllee\Cms\Filament\Resources\Users;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\User as UserContract;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Resources\Users\Pages\CreateUser;
use Mmoollllee\Cms\Filament\Resources\Users\Pages\EditUser;
use Mmoollllee\Cms\Filament\Resources\Users\Pages\ListUsers;

class UserResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static bool $isScopedToTenant = false;

    protected static \UnitEnum|string|null $navigationGroup = 'Global';

    protected static ?string $navigationLabel = 'Benutzer';

    protected static ?string $modelLabel = 'Benutzer';

    protected static ?string $pluralModelLabel = 'Benutzer';

    public static function getModel(): string
    {
        return Cms::userModel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->required()
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->confirmed()
                    ->maxLength(255),
                TextInput::make('password_confirmation')
                    ->password()
                    ->requiredWith('password')
                    ->dehydrated(false)
                    ->maxLength(255),
                Select::make('role')
                    ->label('Rolle')
                    ->options(TenantUserRole::options())
                    ->required()
                    ->default(TenantUserRole::Editor->value),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Rolle')
                    ->state(fn (UserContract $record): ?string => $record->tenantRole(Filament::getTenant())?->value)
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Erstellt')
                    ->dateTime()
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return Cms::userModel()::query()
            ->whereHas('tenants', fn (Builder $query) => $query->where('tenants.id', $tenant->id));
    }
}
