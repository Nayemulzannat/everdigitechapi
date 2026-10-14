<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per signed-in client browser. Laravel's own `sessions` table can't be used for "your devices": its user_id
// follows the default (admin) guard, and one session can hold a staff and a client sign-in at once. A middleware keeps
// this table up to date on every authenticated client request.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->constrained('client_users')->cascadeOnDelete();
            // The Laravel session id. A secret: never sent to the browser as data (the row id is used instead).
            $table->string('session_id', 100)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->timestamp('last_active_at')->useCurrent();
            $table->timestamps();

            $table->index(['client_user_id', 'last_active_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sessions');
    }
};
