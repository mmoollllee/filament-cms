<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link column for the optional Umami integration (mmoollllee/filami): the
     * provisioning job stores the created website's uuid here via filami's
     * attribute conventions. Guarded so installs whose create_tenants_table
     * already grew the column stay no-ops.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'umami_website_id')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('umami_website_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenants', 'umami_website_id')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['umami_website_id']);
            $table->dropColumn('umami_website_id');
        });
    }
};
