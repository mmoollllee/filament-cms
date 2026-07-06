<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\media;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;

class MediaBlock extends BaseBuilderBlock
{
    public function key(): string
    {
        return 'media';
    }

    public function make(?Tenant $tenant): Block
    {
        return Block::make('media')
            ->icon(Heroicon::OutlinedPhoto)
            ->label('Media')
            ->title('title', placeholder: 'Titel', suffix: 'Media')
            ->preview('blocks::media.preview')
            ->schema([
                ...static::optionHiddenFields(),
                Hidden::make('is_video')
                    ->default(false)
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Hidden $component, Get $get): void {
                        $component->state(static::detectVideo($get('media_path')));
                    }),
                FileUpload::make('media_path')
                    ->label('Bild / Video')
                    ->disk('public')
                    ->visibility('public')
                    ->directory(static::uploadDirectory($tenant))
                    ->acceptedFileTypes(['image/*', 'video/mp4', 'video/webm', 'video/quicktime'])
                    ->imagePreviewHeight('120')
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('is_video', static::detectVideo($state));
                    }),
                Select::make('video_quality')
                    ->label('Video-Qualität')
                    ->options([
                        'high' => 'Hoch (größere Datei)',
                        'medium' => 'Mittel (empfohlen)',
                        'low' => 'Niedrig (kleinste Datei)',
                    ])
                    ->default('medium')
                    ->visible(fn (Get $get): bool => (bool) $get('is_video')),
                Toggle::make('video_keep_audio')
                    ->label('Audio beibehalten')
                    ->default(true)
                    ->helperText('Deaktivieren für stumme Hintergrundvideos')
                    ->visible(fn (Get $get): bool => (bool) $get('is_video')),
                FileUpload::make('poster_path')
                    ->label('Poster-Bild (für Video)')
                    ->disk('public')
                    ->visibility('public')
                    ->directory(static::uploadDirectory($tenant))
                    ->image()
                    ->imagePreviewHeight('120')
                    ->visible(fn (Get $get): bool => (bool) $get('is_video')),
                TextInput::make('media_alt')
                    ->label('Alt-Text')
                    ->helperText('Fallback: Titel des Blocks')
                    ->maxLength(255),
            ]);
    }

    /**
     * Detect whether the uploaded file is a video.
     *
     * Handles both TemporaryUploadedFile (during live upload) and
     * string paths (after save / when reopening the block).
     */
    protected static function detectVideo(mixed $value): bool
    {
        if (is_array($value)) {
            $value = array_values($value)[0] ?? null;
        }

        if ($value instanceof TemporaryUploadedFile) {
            return str_starts_with((string) $value->getMimeType(), 'video/');
        }

        return filled($value) && str($value)->lower()->endsWith(['.mp4', '.webm', '.mov']);
    }
}
