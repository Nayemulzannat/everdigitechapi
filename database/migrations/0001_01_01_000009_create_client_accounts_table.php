<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer. An account owns the services, domains and invoices; people reach it through account_members.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->foreignId('owner_user_id')->constrained('client_users')->restrictOnDelete();
            $table->enum('client_group', ['regular', 'reseller', 'vip'])->default('regular');
            $table->enum('status', ['active', 'inactive', 'closed'])->default('active');
            $table->decimal('credit_balance', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The Clients list filters on status and group, searches name and company, and sorts on name and join date.
            $table->index(['status', 'client_group']);
            $table->index('client_group');
            $table->index('name');
            $table->index('company');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_accounts');
    }
};
