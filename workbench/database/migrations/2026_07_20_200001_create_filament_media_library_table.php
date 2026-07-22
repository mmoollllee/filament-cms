<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (Schema::hasTable('filament_media_library')) {
            return;
        }

        Schema::create('filament_media_library', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('uploader', 'fml_uploader_index');
            $table->string('caption')->nullable();
            $table->string('alt_text')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('filament_media_library');
    }
};
