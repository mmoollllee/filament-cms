<?php

namespace Mmoollllee\Cms\Support\Analytics;

/**
 * Capability gate for the optional Umami integration (mmoollllee/filami),
 * mirroring {@see \Mmoollllee\Cms\Support\Media\MediaLibrary}.
 *
 * Probes the LOADED service provider rather than just the class: filami is a
 * require-dev/suggest dependency, so in dev and testbench setups the class can
 * sit in the vendor dir without the app registering it — the same reason the
 * consent-control block in the site layout guards on getProviders(). Provider
 * registration completes before any boot(), so this is stable from boot()
 * onwards; {@see \Mmoollllee\Cms\Cms::flush()} resets the memo between tests.
 */
final class Umami
{
    private static ?bool $installedMemo = null;

    /** Whether filami is installed AND its provider is registered with the app. */
    public static function installed(): bool
    {
        return self::$installedMemo ??= class_exists(\Mmoollllee\Filami\FilamiServiceProvider::class)
            && filled(app()->getProviders(\Mmoollllee\Filami\FilamiServiceProvider::class));
    }

    public static function flush(): void
    {
        self::$installedMemo = null;
    }
}
