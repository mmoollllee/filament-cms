<?php

namespace Mmoollllee\Cms\Support\Media;

/**
 * Hands the first media item a request renders a high loading priority.
 *
 * The first media on a server-rendered page is its hero, usually the Largest Contentful Paint
 * element. With `loading="lazy"` the browser waits for layout before it even requests the file,
 * so the biggest thing above the fold arrives last. Every later media item stays lazy.
 *
 * A lazily fetched fragment (an onepager section loaded on scroll) {@see forgo()}es the claim:
 * it is below the fold by definition.
 *
 * The flag lives on the current request's attributes, so it resets with every request — also
 * under Octane and across the several requests a single test may send.
 */
final class MediaLoadingPriority
{
    private const ATTRIBUTE = 'cms.media_priority_claimed';

    /**
     * True for the first caller in the current request, false for everyone after it.
     */
    public static function claim(): bool
    {
        $attributes = request()->attributes;

        if ($attributes->get(self::ATTRIBUTE, false)) {
            return false;
        }

        $attributes->set(self::ATTRIBUTE, true);

        return true;
    }

    /**
     * Give up the claim for the current request, leaving every media item lazy.
     */
    public static function forgo(): void
    {
        request()->attributes->set(self::ATTRIBUTE, true);
    }
}
