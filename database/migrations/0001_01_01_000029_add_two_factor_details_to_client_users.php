<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            // SHA-256 hashes of the unused recovery codes (the list itself is encrypted by the model cast).
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            // The 30-second step of the last code accepted, so one code cannot be used twice.
            $table->unsignedInteger('two_factor_last_step')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('client_users', fn (Blueprint $table) => $table->dropColumn(['two_factor_recovery_codes', 'two_factor_last_step']));
    }
};
