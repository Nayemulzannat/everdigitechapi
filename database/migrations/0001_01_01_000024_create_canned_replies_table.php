<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Answers staff give again and again, saved once. They are shared by all staff who can reply to tickets.
        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100)->unique();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
    }
};
