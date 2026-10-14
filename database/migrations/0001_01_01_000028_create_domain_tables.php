<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The extensions we sell (.com, .net, .com.bd...) and what each costs. Money is a whole number of PAISA.
        // A price of null means "no price set": the website says "Ask for price".
        Schema::create('domain_extensions', function (Blueprint $table) {
            $table->id();
            $table->string('extension', 30)->unique(); // ".com"
            $table->string('tag', 40)->nullable(); // "Popular", "Local favorite"
            $table->unsignedBigInteger('register_price')->nullable();
            $table->unsignedBigInteger('renew_price')->nullable();
            $table->unsignedBigInteger('transfer_price')->nullable();
            $table->boolean('is_featured')->default(false); // shown in the search results (the others only in "More extensions")
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A domain name an account owns.
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('name', 253)->unique(); // "mybrand.com", lower case
            $table->string('registrar', 30)->default('manual'); // manual | dynadot
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled'])->default('pending');
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('locked')->default(true);
            $table->boolean('whois_privacy')->default(true);
            $table->json('nameservers')->nullable();
            $table->text('notes')->nullable(); // for staff
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('domain_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('type', 40); // created, updated, ...
            $table->json('properties')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['domain_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('domain_extensions');
    }
};
