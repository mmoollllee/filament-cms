<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('adds columns missing from a legacy tenants table', function () {
    Schema::disableForeignKeyConstraints();
    Schema::drop('tenants');

    // tenants as created by an early revision of create_tenants_table,
    // before mail_logo_path & friends were edited into it.
    Schema::create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('site_key')->unique();
        $table->string('primary_domain')->unique();
        $table->string('brand_name')->nullable();
        $table->string('logo_path')->nullable();
        $table->string('primary_color')->nullable();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    });

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_15_090000_add_missing_tenant_columns.php';
    $migration->up();

    Schema::enableForeignKeyConstraints();

    expect(Schema::hasColumns('tenants', [
        'mail_logo_path',
        'secondary_logo_path',
        'favicon_path',
        'visibility',
        'contact_fax',
        'footer_text',
        'spam_questions',
    ]))->toBeTrue();
});

it('is a no-op on an up-to-date tenants table', function () {
    $before = Schema::getColumnListing('tenants');

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_15_090000_add_missing_tenant_columns.php';
    $migration->up();

    expect(Schema::getColumnListing('tenants'))->toBe($before);
});
