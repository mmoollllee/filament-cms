<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\media;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Forms\MediaField;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

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
                MediaField::media('media_path', legacyDirectory: static::uploadDirectory($tenant))
                    ->label('Bild / Video')
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('is_video', static::detectVideo($state));
                    }),
                // Editor-chosen conversion settings only apply to the legacy
                // path-based pipeline (ConvertsUploadedVideos) — library
                // uploads are (until the upload-time pipeline lands) served
                // as uploaded. Gate PER REF, not on the global plugin flag:
                // hidden fields are not dehydrated, so a global gate would
                // strip the saved quality/audio keys from every legacy video
                // block on its next save once the plugin is installed.
                Select::make('video_quality')
                    ->label('Video-Qualität')
                    ->options([
                        'high' => 'Hoch (größere Datei)',
                        'medium' => 'Mittel (empfohlen)',
                        'low' => 'Niedrig (kleinste Datei)',
                    ])
                    ->default('medium')
                    ->visible(fn (Get $get): bool => static::usesLegacyVideoPipeline($get)),
                Toggle::make('video_keep_audio')
                    ->label('Audio beibehalten')
                    ->default(true)
                    ->helperText('Deaktivieren für stumme Hintergrundvideos')
                    ->visible(fn (Get $get): bool => static::usesLegacyVideoPipeline($get)),
                MediaField::image('poster_path', legacyDirectory: static::uploadDirectory($tenant))
                    ->label('Poster-Bild (für Video)')
                    ->visible(fn (Get $get): bool => (bool) $get('is_video')),
                TextInput::make('media_alt')
                    ->label('Alt-Text')
                    ->helperText(MediaLibrary::enabled()
                        ? 'Fallback: Alt-Text aus der Mediathek, dann Titel des Blocks'
                        : 'Fallback: Titel des Blocks')
                    ->maxLength(255),
            ]);
    }

    /**
     * Whether this block's video runs through the legacy path-based ffmpeg
     * pipeline (quality/audio selects only make sense there — library refs
     * are served as uploaded).
     */
    protected static function usesLegacyVideoPipeline(Get $get): bool
    {
        $ref = $get('media_path');

        return (bool) $get('is_video') && ! MediaUrlResolver::isLibraryRef($ref);
    }

    /**
     * Detect whether the referenced media is a video.
     *
     * Handles TemporaryUploadedFile (during live upload), media-library item
     * ids (picker state) and legacy string paths.
     */
    protected static function detectVideo(mixed $value): bool
    {
        if (is_array($value)) {
            $value = array_values($value)[0] ?? null;
        }

        if ($value instanceof TemporaryUploadedFile) {
            return str_starts_with((string) $value->getMimeType(), 'video/');
        }

        return MediaUrlResolver::isVideo($value);
    }
}
