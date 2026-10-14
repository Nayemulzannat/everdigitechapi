<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_login_histories', function (Blueprint $table) {
            $table->id();
            // Null when the email does not belong to any admin (failed attempt).
            $table->foreignId('admin_id')->nullable()->constrained('admins')->cascadeOnDelete();
            $table->string('email_attempted');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->boolean('successful')->default(false);
            $table->string('failure_reason', 50)->nullable(); // invalid_credentials | locked | suspended
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'created_at']);
            $table->index(['email_attempted', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_login_histories');
    }
};
