<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\RichEditor\Plugins\MediaPlugin;

/**
 * The Mediathek picker, storing a portable identifier.
 *
 * Upstream writes `FileData::getKeyHash()` into the node's `data-id`: the
 * driver key encrypted with the app's `APP_KEY` and base64-encoded. That value
 * is a transport token, not a storage format — it only decrypts in the
 * environment that produced it. Content written on production and pulled into
 * a local database turns into an unresolvable string, and rotating `APP_KEY`
 * does the same to every picked image in place.
 *
 * The item id has none of that: it is what an editor UPLOAD already stores,
 * what {@see MediaUrlResolver} resolves natively, and it survives moving a
 * database between environments.
 *
 * Reading stays compatible in both directions. The picker's own prefill runs
 * the id through `FileData::ensureDecryptedKeyHash()`, which returns a plain id
 * unchanged, and the driver's `findFile()` prefixes a key without a colon
 * itself — so `312` finds `media-library-item:312`. Key hashes already in
 * content keep resolving too, as long as they are read where they were
 * written; `cms:media:repair-picker-ids` rewrites those.
 */
class MediaLibraryPickerPlugin extends MediaPlugin
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getEditorActionImageAttributes(array $data, array $arguments): array
    {
        $attributes = parent::getEditorActionImageAttributes($data, $arguments);

        $id = $attributes['id'] ?? null;

        if (! is_string($id)) {
            return $attributes;
        }

        // The parent has already appended a chosen conversion, so split it back
        // off, swap the key and reassemble rather than dropping the suffix.
        [$key, $conversionArguments] = static::parseCompositeId($id);

        // Decrypting here is safe: this runs in the environment that just
        // encrypted the value, which is the only place it ever works.
        $itemId = MediaUrlResolver::normalize($key);

        if (! is_int($itemId)) {
            return $attributes;
        }

        $attributes['id'] = static::getCompositeId((string) $itemId, $conversionArguments);

        return $attributes;
    }
}
