<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('content_type');
            $table->string('template')->nullable();
            $table->json('layout_preset_ids')->nullable();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('path')->nullable();
            $table->string('visibility')->default('public');
            $table->timestamp('publish_from')->nullable();
            $table->timestamp('publish_until')->nullable();
            $table->json('blocks')->nullable();
            $table->json('payload')->nullable();
            $table->json('references')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'path']);
            $table->index(['tenant_id', 'content_type']);
            $table->index(['tenant_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
