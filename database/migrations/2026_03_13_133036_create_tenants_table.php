<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('site_key')->unique();
            $table->string('primary_domain')->unique();
            $table->string('visibility')->default('public');
            $table->string('brand_name')->nullable();
            $table->string('brand_claim')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('secondary_logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color')->nullable();
            $table->string('default_locale')->default('de');
            $table->string('timezone')->default('Europe/Berlin');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company_name')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_fax')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->text('footer_text')->nullable();
            $table->json('social_links')->nullable();
            $table->string('default_seo_title')->nullable();
            $table->text('default_seo_description')->nullable();
            $table->string('default_og_image_path')->nullable();
            $table->json('imprint_data')->nullable();
            $table->json('privacy_data')->nullable();
            $table->json('spam_questions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
