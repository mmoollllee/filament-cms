<?php

// Environment-driven CMS settings only. Everything structural (models,
// resources, builder blocks, site discovery, menu locations) is registered in
// code via Mmoollllee\Cms\Cms in a service provider, and panel options (path,
// vite theme, …) live on the Panel in the app's PanelProvider — see
// docs/CUSTOMIZATION.md. These defaults merge UNDER the app config; publishing
// this file is optional since every value is env-backed.

return [

    /*
    | Tenant whose branding is inherited by tenants without own values.
    | Defaults to lowest-id tenant.
    */
    'default_branding_tenant_id' => env('CMS_BRANDING_TENANT_ID'),

    /*
    | Local-dev only: credentials the panel login form prefills when
    | APP_ENV=local (null = never prefill). Credentials belong in the
    | environment, not in code.
    */
    'dev_login' => [
        'email' => env('CMS_DEV_LOGIN_EMAIL'),
        'password' => env('CMS_DEV_LOGIN_PASSWORD'),
    ],

    /*
    | Redirects & 404 management (redirection.me-style). All lookups are served from a
    | per-tenant cached map so valid pages add no DB queries; 404 logging + hit counting
    | happen after the response is flushed (deferred) and throttled.
    |
    | enabled            master switch for active-redirect resolution.
    | log_not_found      collect unmatched paths into not_found_logs.
    | count_hits         maintain redirect/404 hit counters (deferred).
    | auto_redirect      auto-redirect the visitor when the fuzzy resolver is very confident.
    | auto_threshold     score (0..1) at/above which a match auto-redirects.
    | suggest_threshold  score (0..1) at/above which a match is offered as "Meinten Sie?".
    | max_suggestions    how many "Meinten Sie?" links to show.
    | min_hits           only persist an auto/suggested redirect once a path has been seen this
    |                    many times (anti-bot; the current visitor is still redirected).
    | auto_status        HTTP status for machine-created redirects (302 = temporary/revertible).
    | confirmed_status   status an automatic redirect is promoted to once an admin edits it.
    | prune_after_days   delete low-traffic 404 logs older than this many days.
    | prune_min_hits     404 logs with fewer hits than this are eligible for pruning.
    | ignore_extensions  request paths ending in these are never logged (bot/probe noise).
    */
    'redirects' => [
        'enabled' => true,
        'log_not_found' => true,
        'count_hits' => true,
        'auto_redirect' => true,
        'auto_threshold' => 0.92,
        'suggest_threshold' => 0.5,
        'max_suggestions' => 3,
        'min_hits' => 2,
        'auto_status' => 302,
        'confirmed_status' => 301,
        'prune_after_days' => 90,
        'prune_min_hits' => 3,
        'ignore_extensions' => ['php', 'env', 'asp', 'aspx', 'cgi', 'jsp', 'sql', 'bak'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant invitations
    |--------------------------------------------------------------------------
    | expires_after_days  how long an invitation link stays valid. It bounds the
    |        signed accept URL as well as the model check, so a lapsed link is
    |        refused by the signature before any row is read. An expired
    |        invitation stays in the panel list and can be re-sent.
    */
    'invitations' => [
        'expires_after_days' => (int) env('CMS_INVITATIONS_EXPIRE_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioning (HasVersions)
    |--------------------------------------------------------------------------
    | keep   snapshot versions kept per record (older ones are pruned and
    |        force-deleted on each new version). 0 = unlimited — mind that a
    |        SNAPSHOT stores the full blocks/payload JSON per applied save.
    */
    'versions' => [
        'keep' => (int) env('CMS_VERSIONS_KEEP', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Editorial locking (HasLocks)
    |--------------------------------------------------------------------------
    | timeout  seconds a lock survives without a presence heartbeat. Sized
    |          against the panel's 30s heartbeat (BasePanelProvider's
    |          presencePollingInterval), NOT against how long someone
    |          edits — a live tab keeps refreshing. Too low and a slow network
    |          hands the record away mid-edit; too high and a crashed tab blocks
    |          it for that long (take-over is the escape hatch).
    */
    'locking' => [
        'timeout' => (int) env('CMS_LOCK_TIMEOUT', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media conversions (CmsMediaLibraryDriver)
    |--------------------------------------------------------------------------
    | Governs the image derivatives the media library pre-generates. Storage
    | cost scales with BOTH values: every library image yields the `responsive`,
    | `800`, `400`, `thumb` and `og` conversions plus one srcset candidate per
    | responsive step, so an uncapped 4000px original can occupy 25x its own
    | size on disk.
    |
    | max_width  longest edge (px) the `responsive` conversion — and with it
    |        every srcset candidate derived from it — is capped to. Applied as
    |        Fit::Max, so smaller images are never upscaled and aspect ratios
    |        are preserved. Sized against real viewports: a 1920px candidate
    |        already covers a 2x DPR phone and a 1x desktop. 0 disables the cap
    |        and restores the vendor default (candidates up to original size).
    | format  image format for the frontend conversions (`responsive`, `800`,
    |        `400`, `thumb`) and, because responsive candidates inherit the
    |        format of the conversion they derive from, for the srcset too.
    |        `webp` roughly halves the bytes at comparable quality. Set to
    |        `jpg` for installs that must serve pre-WebP clients.
    | og_format  kept SEPARATE from `format` on purpose: `og` feeds og:image,
    |        and messenger link-preview crawlers are the last holdout of
    |        WebP support. Change only if no one shares links to the site.
    |
    | Changing either value affects NEW conversions only. Re-run
    | `php artisan media-library:regenerate` for existing media, then
    | `php artisan cms:media:prune` to delete the superseded files.
    */
    'media' => [
        'max_width' => (int) env('CMS_MEDIA_MAX_WIDTH', 1920),
        'format' => env('CMS_MEDIA_FORMAT', 'webp'),
        'og_format' => env('CMS_MEDIA_OG_FORMAT', 'jpg'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video conversion (ConvertsUploadedVideos)
    |--------------------------------------------------------------------------
    | recompress_threshold  size in bytes above which an upload that is ALREADY
    |        MP4 gets re-encoded anyway. Non-MP4 containers (.mov/.avi/.wmv) are
    |        converted regardless of size, so this value only governs the "large
    |        MP4" case. Raise it for sites whose hero videos are legitimately big;
    |        lower it in tests, so the size branch can be exercised with a tiny
    |        fixture instead of a multi-megabyte one.
    */
    'video' => [
        'recompress_threshold' => (int) env('CMS_VIDEO_RECOMPRESS_THRESHOLD', 10 * 1024 * 1024),
    ],

];
