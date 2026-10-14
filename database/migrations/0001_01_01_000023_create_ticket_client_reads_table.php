<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How far each person has read each ticket: the id of the latest support reply they have seen.
        // A support reply with a higher id than that is "new" for that person. Each person has their own place.
        Schema::create('ticket_client_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('client_users')->cascadeOnDelete();
            $table->unsignedBigInteger('read_reply_id')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['ticket_id', 'client_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_client_reads');
    }
};
