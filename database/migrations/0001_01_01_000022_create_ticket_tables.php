<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A ticket belongs to an account (not to one person), so everyone allowed to see tickets in that account sees it.
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('client_users')->nullOnDelete();
            $table->foreignId('department_id')->constrained('ticket_departments');
            $table->foreignId('assigned_to')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('subject', 150);
            // open = waiting for support, answered = support replied (waiting for the client), closed.
            $table->enum('status', ['open', 'answered', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->enum('last_reply_by', ['client', 'staff'])->default('client');
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'last_reply_at']);
            $table->index(['status', 'priority', 'last_reply_at']);
            $table->index('assigned_to');
        });

        // The first message of a ticket is also a reply, so a ticket is just a thread.
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->enum('author_type', ['client', 'staff']);
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false); // a note between staff: the client never sees it
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'created_at']);
        });

        // Files live on the private disk and are served only through the API, after a permission check.
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reply_id')->constrained('ticket_replies')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_departments');
    }
};
