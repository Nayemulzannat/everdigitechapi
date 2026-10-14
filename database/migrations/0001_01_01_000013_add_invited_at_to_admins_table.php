<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Invited" = an account whose owner has not set a password yet. Set when the invite email is sent (or re-sent)
// and cleared as soon as the person sets their password through the invite link.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('status');
            $table->index(['status', 'invited_at']);
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropIndex(['status', 'invited_at']);
            $table->dropColumn('invited_at');
        });
    }
};
