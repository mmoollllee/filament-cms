<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('not_found_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedBigInteger('hits')->default(1);
            $table->string('last_referer')->nullable();
            $table->string('last_user_agent')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'path']);
            $table->index(['tenant_id', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('not_found_logs');
    }
};
