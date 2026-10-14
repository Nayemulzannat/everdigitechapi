<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            // Path on the public disk, e.g. avatars/12-ab3dEf9k.webp
            $table->string('avatar_path')->nullable()->after('phone_verified_at');
        });

        // Tokens of the `client_users` password broker (config/auth.php): 60 minutes, separate from the staff ones.
        Schema::create('client_user_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_user_password_reset_tokens');
        Schema::table('client_users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
