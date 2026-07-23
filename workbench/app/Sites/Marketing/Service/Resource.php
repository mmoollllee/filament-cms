<?php

namespace Workbench\App\Sites\Marketing\Service;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Filament\Resources\Concerns\RendersPageHeader;
use Workbench\App\Sites\Marketing\Service\Pages\CreatePage;
use Workbench\App\Sites\Marketing\Service\Pages\EditPage;
use Workbench\App\Sites\Marketing\Service\Pages\ListPage;
use Workbench\App\Sites\Marketing\Service\Pages\RevisionsPage;

class Resource extends TenantScopedContentResource
{
    use RendersPageHeader;

    /**
     * @return array<int, Component>
     */
    protected static function detailSections(?Tenant $tenant): array
    {
        return [
            Section::make('Service details')
                ->schema([
                    Textarea::make('payload.teaser')
                        ->label('Teaser')
                        ->rows(3),
                    TextInput::make('payload.badge')
                        ->label('Badge')
                        ->maxLength(255),
                    TextInput::make('payload.icon')
                        ->label('Icon / short label')
                        ->maxLength(255),
                ]),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPage::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
            'revisions' => RevisionsPage::route('/{record}/revisions'),
        ];
    }
}
