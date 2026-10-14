<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An owner invites someone by email. The person may not have an EverDigitech login yet, so this is not an
        // account_members row: that row is created when the invitation is accepted. Only the SHA-256 of the token is stored.
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('email');
            $table->json('permissions'); // what the new member may do, e.g. ["invoices.view", "hosting.view"]
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('client_users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
