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

];
