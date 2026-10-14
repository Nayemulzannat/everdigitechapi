<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Links a person (client_users) to an account, with what they may do there.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('client_users')->cascadeOnDelete();
            $table->enum('role', ['owner', 'member'])->default('member');
            $table->json('permissions')->nullable(); // e.g. ["invoices.view", "hosting.manage"]; owners have everything
            $table->enum('hosting_scope', ['all', 'selected'])->default('all');
            $table->enum('domain_scope', ['all', 'selected'])->default('all');
            $table->enum('status', ['active', 'invited', 'suspended'])->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('client_users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_members');
    }
};
