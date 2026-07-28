<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant Umami endpoint for the optional analytics integration
     * (mmoollllee/filami). Together with umami_website_id it lets a site be
     * tracked entirely from the panel, against its own Umami server and
     * without any env configuration. Blank falls back to UMAMI_URL.
     *
     * Separate from add_umami_website_id_to_tenants_table because that one has
     * already run on installs carrying the first revision of the integration.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'umami_url')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('umami_url')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenants', 'umami_url')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('umami_url');
        });
    }
};
