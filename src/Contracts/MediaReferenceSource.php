<?php

namespace Mmoollllee\Cms\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mmoollllee\Cms\Support\Media\References\MediaUsage;

/**
 * A place where media-library items are referenced: the CMS registers its own
 * (contents, fragments, tenant settings); an app adds the models it stores
 * item ids in via {@see \Mmoollllee\Cms\Cms::registerMediaReferenceSources()}.
 *
 * What the sources report decides three things: the usage list in the
 * Mediathek, whether an item may be deleted, and which files stored before the
 * private library keep their old link
 * ({@see \Mmoollllee\Cms\Support\Media\LegacyMediaPaths}). A reference a
 * source misses is one an editor can delete out from under a page.
 */
interface MediaReferenceSource
{
    /**
     * @param  Collection<int, Model>  $tenants
     * @return iterable<MediaUsage>
     */
    public static function mediaUsagesForTenants(Collection $tenants): iterable;

}
