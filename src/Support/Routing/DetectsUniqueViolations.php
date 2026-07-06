<?php

namespace Mmoollllee\Cms\Support\Routing;

use Illuminate\Database\QueryException;

/**
 * Shared detection for a "duplicate row" DB error, so the redirect/404 write paths that race
 * on the `(tenant_id, path)` / `(tenant_id, from_path)` unique indexes handle the loser of a
 * concurrent insert identically (and a driver/error-code tweak lives in one place).
 */
trait DetectsUniqueViolations
{
    protected function isUniqueViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
