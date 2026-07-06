<?php

namespace Mmoollllee\Cms\Filament\Resources\Fragments;

use BackedEnum;
use Blendbyte\FilamentTitleWithSlug\TitleWithSlugInput;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Builder\Block;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Forms\BlockBuilder;
use Mmoollllee\Cms\Filament\Resources\Fragments\Pages\CreateFragment;
use Mmoollllee\Cms\Filament\Resources\Fragments\Pages\EditFragment;
use Mmoollllee\Cms\Filament\Resources\Fragments\Pages\ListFragments;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Tenant-scoped, builder-based content fragments (title + slug + blocks). Concrete +
 * registered by apps directly (no subclass); the model is resolved via
 * {@see Cms::fragmentModel()}. Override {@see static::fragmentBlocks()} (in a subclass)
 * only to restrict the available block set.
 */
class FragmentResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static \UnitEnum|string|null $navigationGroup = 'Inhalt';

    protected static ?string $modelLabel = 'Fragment';

    protected static ?string $pluralModelLabel = 'Fragmente';

    protected static ?int $navigationSort = 90;

    public static function getModel(): string
    {
        return Cms::fragmentModel();
    }

    /**
     * The block set offered in the fragment builder. Defaults to all registered blocks;
     * override to restrict (e.g. only 'section').
     *
     * @return array<int, Block>
     */
    protected static function fragmentBlocks(?Tenant $tenant): array
    {
        return app(BuilderBlockRegistry::class)->all($tenant);
    }

    public static function form(Schema $schema): Schema
    {
        $tenant = app(CurrentTenant::class)->get();

        return $schema->components([
            TitleWithSlugInput::make(
                fieldTitle: 'title',
                fieldSlug: 'slug',
                urlPath: '',
                urlHostVisible: false,
                urlVisitLinkVisible: false,
                titleLabel: 'Titel',
                slugLabel: 'Identifier',
                slugRuleUniqueParameters: [
                    'modifyRuleUsing' => function (Unique $rule): Unique {
                        return $rule->where('tenant_id', Filament::getTenant()?->getKey());
                    },
                    'ignorable' => fn (?Model $record): ?Model => $record,
                ],
                slugRuleRegex: '/^[a-z0-9\-\_]*$/',
                slugSlugifier: fn (string $text): string => Str::slug($text),
            )->columnSpanFull(),
            BlockBuilder::make('blocks', $tenant, static::fragmentBlocks($tenant))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->label('Titel')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Model $record): string => (string) $record->slug),
                TextColumn::make('blocks')
                    ->label('Blöcke')
                    ->state(fn (Model $record): string => count($record->blocks ?? []).' Block(s)'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): EloquentBuilder
    {
        $tenant = Filament::getTenant();

        $query = parent::getEloquentQuery();

        if ($tenant === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('tenant_id', $tenant->getKey());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFragments::route('/'),
            'create' => CreateFragment::route('/create'),
            'edit' => EditFragment::route('/{record}/edit'),
        ];
    }
}
