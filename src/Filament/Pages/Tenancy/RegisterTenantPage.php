<?php

namespace Mmoollllee\Cms\Filament\Pages\Tenancy;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\TenantVisibility;

class RegisterTenantPage extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Tenant anlegen';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('site_key')
                    ->label('Site-Key')
                    ->helperText('Bestimmt die geladene Site-Extension (Content-Typen, Views).')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash(),
                TextInput::make('primary_domain')
                    ->label('Primäre Domain')
                    ->required()
                    ->maxLength(255),
                Select::make('visibility')
                    ->label('Sichtbarkeit')
                    ->required()
                    ->options(TenantVisibility::options())
                    ->default(TenantVisibility::Public->value),
                TextInput::make('brand_name')
                    ->label('Markenname')
                    ->maxLength(255),
                TextInput::make('brand_claim')
                    ->label('Claim')
                    ->maxLength(255),
                TextInput::make('default_locale')
                    ->label('Sprache')
                    ->required()
                    ->default('de')
                    ->maxLength(8),
                TextInput::make('timezone')
                    ->label('Zeitzone')
                    ->required()
                    ->default('Europe/Berlin')
                    ->maxLength(64),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeRegister(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        return Cms::tenantModel()::query()->create($data);
    }
}
