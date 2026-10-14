<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which hosting services / domains a member can reach when their scope is "selected".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_member_resources', function (Blueprint $table) {
            $table->foreignId('member_id')->constrained('account_members')->cascadeOnDelete();
            $table->enum('resource_type', ['hosting', 'domain']);
            // TODO: add a foreign key once the hosting services and domains tables exist.
            $table->unsignedBigInteger('resource_id');

            $table->primary(['member_id', 'resource_type', 'resource_id']);
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_member_resources');
    }
};
