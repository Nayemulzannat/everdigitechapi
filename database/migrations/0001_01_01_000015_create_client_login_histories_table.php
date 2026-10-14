<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_login_histories', function (Blueprint $table) {
            $table->id();
            // Null when the email does not belong to any client user (failed attempt).
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->cascadeOnDelete();
            $table->string('email_attempted');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->boolean('successful')->default(false);
            $table->string('failure_reason', 50)->nullable(); // invalid_credentials | locked | suspended
            // Set when a staff member signed in as this client ("log in as client"), so it is never mistaken for the client.
            $table->foreignId('impersonated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_user_id', 'created_at']);
            $table->index(['email_attempted', 'created_at']);
            // "Has this person signed in from this browser and system before?" (the new-device alert)
            $table->index(['client_user_id', 'successful', 'browser', 'platform'], 'client_login_histories_device_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_login_histories');
    }
};
