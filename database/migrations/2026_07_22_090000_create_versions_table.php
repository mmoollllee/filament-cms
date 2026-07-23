<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content versioning (overtrue/laravel-versionable via HasVersions).
     * Consolidates the upstream migration trio (create + deleted_at +
     * nullable user) into the engine's publishable set — the same shape
     * `vendor:publish --provider="Overtrue\LaravelVersionable\ServiceProvider"`
     * would produce, so either path yields an identical table.
     */
    public function up(): void
    {
        if (Schema::hasTable('versions')) {
            return;
        }

        Schema::create('versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger(config('versionable.user_foreign_key', 'user_id'))->nullable();
            $table->morphs('versionable');
            $table->json('contents')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions');
    }
};
