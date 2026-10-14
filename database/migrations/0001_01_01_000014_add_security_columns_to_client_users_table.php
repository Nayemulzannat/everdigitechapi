<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sign-in security for customers, the same idea as on `admins`: failed attempts and a lock, the last sign-in, and the
// account the person was last working in. (`last_login_at` already exists on client_users, so it isn't added again.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('two_factor_confirmed_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            // The account the panel opens on next time. Cleared if that account is ever removed.
            $table->foreignId('last_account_id')->nullable()->after('last_login_ip')->constrained('client_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_account_id');
            $table->dropColumn(['phone_verified_at', 'failed_login_attempts', 'locked_until', 'last_login_ip']);
        });
    }
};
