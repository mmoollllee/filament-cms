<?php

namespace Mmoollllee\Cms\Filament\Pages\Tenancy;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Mmoollllee\Cms\Support\Tenancy\TenantDomain;

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
                    // Same shape and the same uniqueness the edit page enforces:
                    // this is where a tenant is BORN, so an unnormalized paste
                    // here produces a site that answers no request at all, and a
                    // duplicate would surface as the DB unique index blowing up
                    // instead of as a form error.
                    ->rules(TenantDomain::rules())
                    ->unique(table: fn (): string => (new (Cms::tenantModel()))->getTable(), column: 'primary_domain')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Set $set): mixed => $set(
                        'primary_domain',
                        TenantDomain::normalize($state) ?? $state,
                    ))
                    ->helperText(new HtmlString(
                        'Nur der Host — ohne <code>https://</code> und ohne Pfad; eine eingefügte URL wird beim Verlassen des Felds gekürzt.'
                    )),
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
