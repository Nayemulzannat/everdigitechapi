<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Sections of the plan comparison on the pricing page: [{"title": "Server", "items": ["1 Core CPU", ...]}]
            $table->json('feature_groups')->nullable()->after('features');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            // The "regular" price shown struck through next to the real one (paisa, whole cycle). Display only.
            $table->unsignedBigInteger('compare_amount')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', fn (Blueprint $table) => $table->dropColumn('compare_amount'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('feature_groups'));
    }
};
