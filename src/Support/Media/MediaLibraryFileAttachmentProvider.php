<?php

namespace Mmoollllee\Cms\Support\Media;

use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\RichEditor\Plugins\FilePlugin;
use RuntimeException;

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
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Required by the contract; this provider needs no per-field state, so the
     * attribute is not kept.
     */
    public function attribute(RichContentAttribute $attribute): static
    {
        return $this;
    }

    /**
     * The URL for a stored identifier, or null when it is an item the current
     * tenant may not see.
     *
     * Path-shaped ids — left by Filament's default provider or a WordPress
     * import — are passed through untouched, so switching this provider on does
     * not turn existing content into broken images.
     */
    public function getFileAttachmentUrl(mixed $file): ?string
    {
        [$ref, $conversion] = $this->parseIdentifier($file);

        // Normalize FIRST, then check. The picker stores the item key encrypted
        // (`base64(AES("media-library-item:42"))`), which is neither numeric nor
        // a path — checking the raw value would skip the guard entirely while
        // the resolver went on to resolve it anyway.
        $ref = MediaUrlResolver::normalize($ref);

        // An id is client-controlled: the editor ships a source-code tab, so an
        // editor on one tenant can paste another tenant's identifier and have
        // the site render — and, on a private disk, sign — a file they may not
        // read. The item model carries only a `has_media` scope, so the check is
        // here, and without a tenant to scope against nothing is trusted.
        if (is_int($ref) && ! $this->belongsToCurrentTenant($ref)) {
            return null;
        }

        return MediaUrlResolver::url($ref, $conversion);
    }

    /**
     * Peel the conversion off a stored node identifier.
     *
     * Two writers share this attribute: an upload stores a plain item id, the
     * Mediathek picker stores the item key encrypted and, when one was chosen,
     * suffixed `|conversion`. Only that suffix needs handling here —
     * {@see MediaUrlResolver::normalize()} already decrypts the key itself.
     *
     * The suffix is accepted only when it looks like a conversion NAME, so a
     * legacy path that happens to contain a pipe is not silently cut in half.
     *
     * @return array{0: mixed, 1: string|null}
     */
    protected function parseIdentifier(mixed $file): array
    {
        if (! is_string($file) || ! str_contains($file, '|')) {
            return [$file, null];
        }

        [$key, $arguments] = FilePlugin::parseCompositeId($file);
        $conversion = $arguments[0] ?? null;

        return preg_match('/^[A-Za-z0-9_-]+$/', (string) $conversion) === 1
            ? [$key, $conversion]
            : [$file, null];
    }

    protected function belongsToCurrentTenant(int $id): bool
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return false;
        }

        // Read from the memoized lookup that resolves the URL a line later, not
        // a query of its own: the renderer calls this for EVERY image node in
        // every rich-text field, so a separate SELECT here is one per image per
        // page render.
        $item = MediaUrlResolver::item($id);

        return $item !== null
            && $item->tenant_id === $tenant->getKey()
            && $item->tenant_type === $tenant->getMorphClass();
    }

    /**
     * @return int the media-library item id
     *
     * @throws RuntimeException when there is no tenant to file the upload under
     */
    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): mixed
    {
        $tenant = $this->tenant();

        // Returning null here would be worse than failing: Filament writes the
        // result straight into the node's id and src, and the next dehydration
        // drops a node with a blank id — the image is gone, with no exception,
        // no notification and nothing in the log. Fail loudly instead.
        if ($tenant === null) {
            throw new RuntimeException('Cannot store a rich-editor upload: no tenant context. The media library files every upload under a tenant.');
        }

        $fileName = $this->fileNameFor($file);

        // One unit of work: the item row is invisible to the whole application
        // without media (the model carries a `has_media` global scope), so a
        // half-done upload would leave a row nothing can list, edit or delete.
        return DB::transaction(function () use ($tenant, $file, $fileName): int {
            $item = Cms::mediaItemModel()::query()->create([
                'tenant_type' => $tenant->getMorphClass(),
                'tenant_id' => $tenant->getKey(),
                // Editor uploads belong to a page, which is what the Pages
                // folder is for; provisioned lazily on first use.
                'folder_id' => MediaFolders::ensure(MediaFolders::PAGES, $tenant)?->getKey(),
            ]);

            $item
                ->driver(app(Cms::mediaDriver()))
                // From the DISK, not a real path: Livewire's temporary upload
                // disk may be remote, where getRealPath() is an object key and
                // not a file. Preserving the original also leaves the temporary
                // file in place for Filament's own post-upload validation,
                // which still holds a handle to it.
                ->addMediaFromDisk(
                    FileUploadConfiguration::path($file->getFilename(), withS3Root: false),
                    FileUploadConfiguration::disk(),
                )
                ->preservingOriginal()
                ->usingFileName($fileName)
                ->usingName(pathinfo($fileName, PATHINFO_FILENAME))
                ->toMediaCollection($item->getMediaLibraryCollectionName());

            return (int) $item->getKey();
        });
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

        return $isUsable ? $name : $file->getFilename();
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
     * Shared with {@see MediaFolders}: which tenant an upload belongs to is one
     * policy, and two copies of it would let a queued job and the panel disagree.
     */
    protected function tenant(): ?Model
    {
        return MediaFolders::currentTenant();
    }
}
