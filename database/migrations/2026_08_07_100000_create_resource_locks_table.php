<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editorial locks (blendbyte/filament-resource-lock via HasLocks).
     * Carried in the engine's publishable set — same shape as
     * `vendor:publish --tag=filament-resource-lock-migrations`, so either path
     * yields a compatible table and an app that already published the vendor
     * migration is skipped by the guard below.
     *
     * The vendor migration constrains `user_id` to `users`; the engine leaves
     * it unconstrained because the user model (and therefore its table) is
     * app-configurable via {@see \Mmoollllee\Cms\Cms::userModel()}. Stale rows
     * cannot outlive the lock timeout anyway — expired locks are dropped on the
     * next visit and by `filament-resource-lock:clear-expired`.
     */
    public function up(): void
    {
        if (Schema::hasTable('resource_locks')) {
            return;
        }

        Schema::create('resource_locks', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_id')->index();
            $table->morphs('lockable');

            // One lock per record. `resourceLock()` is a latestOfMany() relation,
            // so a second row from two concurrent lock() calls would be invisible
            // to the UI while take-over (which deletes through that relation)
            // removes only one of them — the admin stays blocked with no error.
            $table->unique(['lockable_type', 'lockable_id']);
        });
    }

    public function down(): void
    {
        // Only drop what this migration created. An app that had already run the
        // vendor migration hit the guard in up(), so the table is the vendor's —
        // dropping it on rollback would take every live lock with it, and a
        // re-migrate would not bring it back (the vendor migration still counts
        // as run).
        $vendorMigrationRan = DB::table('migrations')
            ->where('migration', 'like', '%create_resource_lock%table')
            ->exists();

        if ($vendorMigrationRan) {
            return;
        }

        Schema::dropIfExists('resource_locks');
    }
};
