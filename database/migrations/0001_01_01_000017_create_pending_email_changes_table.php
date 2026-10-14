<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "I want a new email address", waiting for a click on the link sent to that new address. The person's real email does
// not change until then. At most one request per person: asking again replaces the old one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_email_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->unique()->constrained('client_users')->cascadeOnDelete();
            $table->string('new_email')->index();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_email_changes');
    }
};
