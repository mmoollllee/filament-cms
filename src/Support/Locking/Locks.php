<?php

namespace Mmoollllee\Cms\Support\Locking;

use Blendbyte\FilamentResourceLock\Actions\GetResourceLockOwnerAction;
use Blendbyte\FilamentResourceLock\Models\Concerns\HasLocks;
use Blendbyte\FilamentResourceLock\ResourceLockPlugin;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Mmoollllee\Cms\Support\TraitAdoption;

/**
 * Capability checks around the {@see HasLocks} trait — same contract as
 * {@see \Mmoollllee\Cms\Support\Preview\Drafts} and
 * {@see \Mmoollllee\Cms\Support\Versioning\Versions}: content models are
 * app-owned, so an install that has not adopted the trait yet keeps working
 * and every locking element turns itself off.
 *
 * On top of the trait check, locking also needs the plugin registered on the
 * CURRENT panel ({@see active()}) — {@see ResourceLockPlugin::get()} resolves
 * through `filament()` and throws for panels without it, so every entry point
 * asks here first.
 */
final class Locks
{
    public const PLUGIN_ID = 'filament-resource-lock';

    /** Whether the model (class or instance) has adopted {@see HasLocks}. */
    public static function supported(object|string|null $model): bool
    {
        return TraitAdoption::adopted(HasLocks::class, $model);
    }

    /**
     * Seconds a lock survives without a heartbeat. Single source of truth for
     * the vendor config bridge and the plugin, which read it from opposite
     * ends ({@see \Mmoollllee\Cms\CmsServiceProvider}).
     */
    public static function timeout(): int
    {
        return (int) config('cms.locking.timeout', 180);
    }

    /**
     * Whether the current user may seize a record somebody else holds. Mirrors
     * the vendor observer component's own gate, resolved against the PANEL's
     * guard — `Gate::allows()` would go through the default guard, which is a
     * different (possibly unauthenticated) user on panels with `authGuard()`.
     */
    public static function canTakeOver(): bool
    {
        if (! self::active()) {
            return false;
        }

        $plugin = ResourceLockPlugin::get();

        if (! $plugin->shouldLimitUnlockerAccess()) {
            return true;
        }

        $gate = $plugin->getUnlockerGate();

        return $gate !== null && Gate::forUser(Filament::auth()->user())->allows($gate);
    }

    /**
     * Whether locking can run at all: plugin on the current panel, and — when
     * a record is given — a model that carries the trait.
     */
    public static function active(object|string|null $record = null): bool
    {
        if (Filament::getCurrentPanel()?->hasPlugin(self::PLUGIN_ID) !== true) {
            return false;
        }

        return $record === null || self::supported($record);
    }

    /**
     * The blocking predicate: someone ELSE holds a live lock on this record.
     * Expired locks do not block ({@see HasLocks::isLocked()} folds the
     * timeout in), so a browser that died without unlocking only blocks for
     * the remainder of the timeout.
     */
    public static function heldByOther(?object $record): bool
    {
        if (! $record instanceof Model || ! self::active($record)) {
            return false;
        }

        return $record->isLocked() && ! self::heldByCurrentUser($record);
    }

    /**
     * Deliberately compares the raw `user_id` instead of the vendor's
     * {@see HasLocks::isLockedByCurrentUser()}: that one walks the `user`
     * relation and fatals on a lock whose user has since been deleted — the
     * exact case where the guard must still answer.
     */
    public static function heldByCurrentUser(?object $record): bool
    {
        if (! $record instanceof Model || ! self::supported($record)) {
            return false;
        }

        $lock = $record->resourceLock;
        $user = Filament::auth()->user();

        return $lock !== null
            && $user !== null
            && (string) $lock->user_id === (string) $user->getAuthIdentifier();
    }

    /**
     * Display name of the current lock holder, via the plugin's configurable
     * owner action. Null when nobody holds it, when the user row is gone, or
     * when the install asked for the name NOT to be shown — the vendor gates
     * its own modal on that flag, so the engine must not leak the name through
     * the notification instead.
     */
    public static function ownerName(?object $record): ?string
    {
        if (! $record instanceof Model || ! self::active($record)) {
            return null;
        }

        if (! ResourceLockPlugin::get()->shouldDisplayResourceLockOwner()) {
            return null;
        }

        $user = $record->resourceLock?->user;

        if ($user === null) {
            return null;
        }

        /** @var GetResourceLockOwnerAction $action */
        $action = app(ResourceLockPlugin::get()->getResourceLockOwnerAction());

        return $action->execute($user);
    }
}
