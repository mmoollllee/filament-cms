<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            // Unique because it IS the credential: the accept link carries
            // nothing else, and a collision would hand one tenant's invitation
            // to someone invited to another.
            $table->string('token', 80)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // One row per address and tenant, re-used on re-invite (the service
            // updates token/expiry/accepted_at in place). Including accepted_at
            // in the key to keep a history instead would not enforce anything:
            // NULLs compare as distinct in MySQL and SQLite, so every open
            // invitation would still be allowed to duplicate.
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};
