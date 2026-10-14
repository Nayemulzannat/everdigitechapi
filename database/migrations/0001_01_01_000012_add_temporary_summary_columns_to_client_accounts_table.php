<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TODO: TEMPORARY. Delete this migration (and the `tmp_*` accessors in ClientResource and the model) when the
 * hosting services, domains and invoices tables are built. Then:
 *   services    = withCount('services')
 *   domains     = withCount('domains')
 *   balanceDue  = sum of the account's unpaid invoices (withSum)
 *   overdue     = exists(unpaid invoice past its due date)
 * Until then the Clients list reads these plain columns, so the response already has its final shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->unsignedInteger('tmp_services_count')->default(0)->after('credit_balance');
            $table->unsignedInteger('tmp_domains_count')->default(0)->after('tmp_services_count');
            $table->decimal('tmp_balance_due', 12, 2)->default(0)->after('tmp_domains_count');
            $table->boolean('tmp_has_overdue')->default(false)->after('tmp_balance_due');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn(['tmp_services_count', 'tmp_domains_count', 'tmp_balance_due', 'tmp_has_overdue']);
        });
    }
};
