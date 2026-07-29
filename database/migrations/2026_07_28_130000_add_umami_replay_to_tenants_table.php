<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant switch for Umami session replay (and the heatmaps built on
     * it), which loads a second recorder script next to the tracker. Umami
     * enables the feature per website, so this mirrors that setting rather
     * than turning it on everywhere.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'umami_replay')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('umami_replay')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenants', 'umami_replay')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('umami_replay');
        });
    }
};
