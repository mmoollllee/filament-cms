<?php

namespace Mmoollllee\Cms\Support\Media;

use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Routes RichEditor image uploads into the media library instead of dropping
 * them on a bare disk path.
 *
 * Filament's default provider stores an attachment as a path under
 * `fileAttachmentsDirectory()` and puts that path in the node's `data-id`. Such
 * a file exists outside every piece of media tooling there is: it has no
 * caption, no alt text, no folder, no tenant, it never appears in the Mediathek,
 * `cms:media:prune` cannot judge it, and a "which media are unused?" question
 * cannot see it either. Editors then had two upload flows with two different
 * fates for the file, depending only on which field they happened to use.
 *
 * With this provider a `data-id` holds a media-library item id, so a picture
 * pasted into a paragraph is the same kind of thing as one chosen through a
 * MediaPicker.
 *
 * Legacy content keeps working: {@see MediaUrlResolver::url()} resolves both
 * ids and stored paths, so a `data-id` left over from the default provider (or
 * from a WordPress import) still renders while it waits to be migrated by
 * `cms:media:import --inline`.
 */
class MediaLibraryFileAttachmentProvider implements FileAttachmentProvider
{
    protected ?RichContentAttribute $attribute = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public function attribute(RichContentAttribute $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * The stored identifier is a media-library item id — but the resolver is
     * deliberately given whatever is there, so pre-existing path-shaped ids
     * keep rendering rather than turning into broken images the moment this
     * provider is switched on.
     */
    public function getFileAttachmentUrl(mixed $file): ?string
    {
        return MediaUrlResolver::url($file);
    }

    /**
     * @return int|null the media-library item id, or null when there is no
     *                  tenant to file the upload under
     */
    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): mixed
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return null;
        }

        $itemModel = Cms::mediaItemModel();

        $item = $itemModel::query()->create([
            'tenant_type' => $tenant->getMorphClass(),
            'tenant_id' => $tenant->getKey(),
            // Editor uploads belong to a page, which is what the Pages folder
            // is for; the folder is provisioned lazily on first use.
            'folder_id' => MediaFolders::ensure(MediaFolders::PAGES, $tenant)?->getKey(),
        ]);

        $fileName = $this->fileNameFor($file);

        $item
            ->driver(app(Cms::mediaDriver()))
            ->addMedia($file->getRealPath())
            ->usingFileName($fileName)
            ->usingName(pathinfo($fileName, PATHINFO_FILENAME))
            ->toMediaCollection($item->getMediaLibraryCollectionName());

        return (int) $item->getKey();
    }

    /**
     * Livewire base64-embeds the original name in the temporary file name and
     * decodes it back on demand. When that fails — a temporary file written by
     * something other than an upload request — the decode yields binary noise,
     * which would become the media's file name and land unreadable on disk.
     * The stored name is the honest fallback.
     */
    protected function fileNameFor(TemporaryUploadedFile $file): string
    {
        $name = $file->getClientOriginalName();

        $isUsable = $name !== ''
            && mb_check_encoding($name, 'UTF-8')
            && ! str_contains($name, '/')
            && preg_match('#\.[A-Za-z0-9]{1,8}$#', $name) === 1;

        return $isUsable ? $name : basename($file->getRealPath());
    }

    /**
     * The library disk decides visibility, not the field: a private-disk install
     * serves through its own URL generator, and returning a value here would
     * override that per editor.
     */
    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return null;
    }

    /**
     * A library item stands on its own — it is created, listed and deleted in
     * the Mediathek — so an upload does not have to wait for the record that
     * happens to embed it. This is what lets an image be pasted into a new,
     * unsaved page.
     */
    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
    }

    /**
     * Deliberately a no-op. Filament calls this to delete attachments dropped
     * from the content, which is right for files owned by one record and wrong
     * here: the same item may be used on ten pages and is managed centrally.
     * Finding genuinely unreferenced items is the Mediathek's job.
     *
     * @param  array<mixed>  $exceptIds
     */
    public function cleanUpFileAttachments(array $exceptIds): void {}

    /**
     * Panel requests carry the Filament tenant; a queued job or a console
     * command falls back to the CMS's own current-tenant registry.
     */
    protected function tenant(): ?Model
    {
        return Filament::getTenant() ?? app(CurrentTenant::class)->get();
    }
}
