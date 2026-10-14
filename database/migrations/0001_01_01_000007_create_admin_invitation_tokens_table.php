<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tokens for the "set your password" invite email (48 hours). Separate from admin_password_reset_tokens
// (60 minutes), so a normal reset link can never live as long as an invite.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitation_tokens');
    }
};
