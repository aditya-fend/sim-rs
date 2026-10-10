<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §1 — hospital personnel. The primary key is a UUID rather than an
     * auto-increment so identifiers are safe to expose in FHIR payloads and
     * cannot be enumerated.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            // Surat Izin Praktik — printed on the Permenkes signature stamp.
            $table->string('sip_number')->nullable();
            $table->char('nik_hash', 64)->nullable();
            $table->string('ihs_practitioner_id')->nullable()->unique();
            // AES-256-GCM ciphertext: a TOTP secret is a shared secret, and
            // losing it means re-enrolling the account.
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_secret_confirmed_at')->nullable();
            // Bcrypt hashes of single-use recovery codes.
            $table->text('two_factor_recovery_codes')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('nik_hash', 'users_nik_hash_index');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
