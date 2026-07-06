<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('menu_locations', function (Blueprint $table) {
            $table->dropUnique(['location']);
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['tenant_id', 'location']);
        });
    }

    public function down(): void
    {
        Schema::table('menu_locations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'location']);
            $table->dropColumn('tenant_id');
            $table->unique(['location']);
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
