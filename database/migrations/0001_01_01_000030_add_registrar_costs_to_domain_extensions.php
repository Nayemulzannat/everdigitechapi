<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_extensions', function (Blueprint $table) {
            // What the registrar charges us, in US cents, from its price list (the sync). Never shown to clients.
            $table->unsignedInteger('cost_register')->nullable()->after('transfer_price');
            $table->unsignedInteger('cost_renew')->nullable()->after('cost_register');
            $table->unsignedInteger('cost_transfer')->nullable()->after('cost_renew');
            $table->string('usage', 120)->nullable()->after('cost_transfer'); // "General Websites"
            $table->string('restrictions', 255)->nullable()->after('usage'); // null = none; else who may register it
        });
    }

    public function down(): void
    {
        Schema::table('domain_extensions', fn (Blueprint $table) => $table->dropColumn(['cost_register', 'cost_renew', 'cost_transfer', 'usage', 'restrictions']));
    }
};
