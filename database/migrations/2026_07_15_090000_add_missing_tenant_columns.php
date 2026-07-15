<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * create_tenants_table gained columns in place over its lifetime (e.g.
     * mail_logo_path), so installs migrated from an earlier revision of that
     * file are missing the newer ones — the migration is already marked as run
     * and never re-executes. Reconciles such tables by adding whichever
     * columns are absent; on fresh installs every column already exists and
     * this is a no-op.
     */
    public function up(): void
    {
        $definitions = [
            'visibility' => fn (Blueprint $table) => $table->string('visibility')->default('public'),
            'brand_name' => fn (Blueprint $table) => $table->string('brand_name')->nullable(),
            'brand_claim' => fn (Blueprint $table) => $table->string('brand_claim')->nullable(),
            'logo_path' => fn (Blueprint $table) => $table->string('logo_path')->nullable(),
            'secondary_logo_path' => fn (Blueprint $table) => $table->string('secondary_logo_path')->nullable(),
            'mail_logo_path' => fn (Blueprint $table) => $table->string('mail_logo_path')->nullable(),
            'favicon_path' => fn (Blueprint $table) => $table->string('favicon_path')->nullable(),
            'primary_color' => fn (Blueprint $table) => $table->string('primary_color')->nullable(),
            'default_locale' => fn (Blueprint $table) => $table->string('default_locale')->default('de'),
            'timezone' => fn (Blueprint $table) => $table->string('timezone')->default('Europe/Berlin'),
            'created_by' => fn (Blueprint $table) => $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(),
            'company_name' => fn (Blueprint $table) => $table->string('company_name')->nullable(),
            'legal_name' => fn (Blueprint $table) => $table->string('legal_name')->nullable(),
            'contact_email' => fn (Blueprint $table) => $table->string('contact_email')->nullable(),
            'contact_phone' => fn (Blueprint $table) => $table->string('contact_phone')->nullable(),
            'contact_fax' => fn (Blueprint $table) => $table->string('contact_fax')->nullable(),
            'street' => fn (Blueprint $table) => $table->string('street')->nullable(),
            'postal_code' => fn (Blueprint $table) => $table->string('postal_code')->nullable(),
            'city' => fn (Blueprint $table) => $table->string('city')->nullable(),
            'country' => fn (Blueprint $table) => $table->string('country')->nullable(),
            'footer_text' => fn (Blueprint $table) => $table->text('footer_text')->nullable(),
            'social_links' => fn (Blueprint $table) => $table->json('social_links')->nullable(),
            'default_seo_title' => fn (Blueprint $table) => $table->string('default_seo_title')->nullable(),
            'default_seo_description' => fn (Blueprint $table) => $table->text('default_seo_description')->nullable(),
            'default_og_image_path' => fn (Blueprint $table) => $table->string('default_og_image_path')->nullable(),
            'imprint_data' => fn (Blueprint $table) => $table->json('imprint_data')->nullable(),
            'privacy_data' => fn (Blueprint $table) => $table->json('privacy_data')->nullable(),
            'spam_questions' => fn (Blueprint $table) => $table->json('spam_questions')->nullable(),
        ];

        $missing = array_diff_key($definitions, array_flip(Schema::getColumnListing('tenants')));

        if ($missing === []) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) use ($missing): void {
            foreach ($missing as $addColumn) {
                $addColumn($table);
            }
        });
    }

    /**
     * Intentionally a no-op: the columns belong to create_tenants_table's
     * current shape — rolling back a reconcile must not destroy tenant data.
     */
    public function down(): void
    {
        //
    }
};
