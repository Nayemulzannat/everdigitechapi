<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the company sells is grouped (Web Hosting, VPS, Domains...). The group says what KIND of thing it is, so
        // later steps know how to set it up (a hosting account, a server, a domain).
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->enum('type', ['hosting', 'server', 'domain', 'sms', 'software', 'service'])->default('service');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true); // off = hidden from the public catalog
            $table->timestamps();
        });

        // One thing a client can buy (a plan): "Turbo Hosting - Business". Deleted ones stay, for old orders to point at.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained('product_groups');
            $table->string('name', 150);
            $table->string('slug', 160)->unique();
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->json('features')->nullable(); // a list of short lines: ["10 GB SSD", "Free SSL"]
            // Money is kept as a whole number of PAISA (100 paisa = 1 taka), never as a float.
            $table->unsignedBigInteger('setup_fee')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_group_id', 'is_active', 'sort_order']);
        });

        // The price of a product for each billing cycle it is sold with. No row = not sold with that cycle.
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('cycle', ['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially', 'one_time']);
            $table->unsignedBigInteger('amount'); // paisa, for the whole cycle
            $table->timestamps();

            $table->unique(['product_id', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
    }
};
