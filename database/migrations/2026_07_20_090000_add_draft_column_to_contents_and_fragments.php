<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `draft` column (unapplied panel changes, see HasDraft) was added in
     * place to create_contents_table / create_fragments_table. Installs that
     * migrated an earlier revision of those files never re-run them, so this
     * reconcile adds the column where it is absent; on fresh installs it is a
     * no-op. Same pattern as 2026_07_15_090000_add_missing_tenant_columns.
     */
    public function up(): void
    {
        foreach (['contents', 'fragments'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'draft')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('draft')->nullable();
            });
        }
    }

    /**
     * Intentionally a no-op: the column belongs to the create migrations'
     * current shape — rolling back a reconcile must not destroy draft data.
     */
    public function down(): void
    {
        //
    }
};
