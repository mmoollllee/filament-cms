<?php

namespace Mmoollllee\Cms\Filament\Resources\Notices;

use BackedEnum;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Filament\Resources\Notices\Pages\CreateNotice;
use Mmoollllee\Cms\Filament\Resources\Notices\Pages\EditNotice;
use Mmoollllee\Cms\Filament\Resources\Notices\Pages\ListNotices;
use Mmoollllee\Cms\Filament\Resources\Notices\Pages\NoticeRevisions;
use Mmoollllee\Cms\Sites\Notice\Blueprint;

/**
 * "Hinweise" in the panel — registered with the default site extension once an
 * app calls {@see Cms::enableNotices()}. A notice is a title plus one rich text;
 * when it shows is the publishing window in the "Einstellungen" tab.
 */
class NoticeResource extends TenantScopedContentResource
{
    /** @var array<int, string> */
    protected static array $contentTypes = [Blueprint::KEY];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    /**
     * @return array<int, Component>
     */
    public static function payloadSections(): array
    {
        return [
            Section::make('Hinweis')
                ->description('Erscheint als Banner, solange das Veröffentlichungsfenster (Tab „Einstellungen“ → „Sichtbarkeit“: von/bis) aktiv ist — danach verschwindet er automatisch. Ideal für Betriebsurlaub, Feiertage oder Ausfälle.')
                ->columnSpanFull()
                ->schema([
                    RichEditor::make('payload.content')
                        ->label('Hinweistext')
                        ->required()
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected static function detailSections(?Tenant $tenant): array
    {
        // No block builder on this type — the payload section forms the "Inhalt" tab.
        return static::payloadSections();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotices::route('/'),
            'create' => CreateNotice::route('/create'),
            'edit' => EditNotice::route('/{record}/edit'),
            'revisions' => NoticeRevisions::route('/{record}/revisions'),
        ];
    }
}
