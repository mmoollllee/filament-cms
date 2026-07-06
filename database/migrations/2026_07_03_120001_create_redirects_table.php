<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('from_path');
            $table->foreignId('to_content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('to_url')->nullable();
            $table->unsignedSmallInteger('status_code')->default(302);
            $table->boolean('is_active')->default(true);
            $table->string('origin')->default('manual');
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One row per (tenant, from_path) across ALL rows incl. trashed: every
            // programmatic write goes through withTrashed()->updateOrCreate, so a
            // previously-deleted path is re-used (and stays "never re-suggested"),
            // never duplicated.
            $table->unique(['tenant_id', 'from_path']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
