<?php

use Datlechin\FilamentMenuBuilder\Enums\LinkTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->nullableMorphs('linkable');
            $table->string('panel')->nullable();
            $table->string('title');
            $table->string('url')->nullable();
            $table->string('icon')->nullable();
            $table->string('classes')->nullable();
            $table->string('rel')->nullable();
            $table->string('target', 10)->default(LinkTarget::Self->value);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->string('location')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_locations');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }
};
