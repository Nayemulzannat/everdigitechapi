<?php

use App\Http\Controllers\Admin\CannedReplyController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\TicketDepartmentController;
use Illuminate\Support\Facades\Route;

// Support tickets for staff. Served under /api/admin.
// tickets.view: read. tickets.edit: reply, add notes, change status, priority and department. tickets.assign: assign.
// Departments are below.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('tickets', [TicketController::class, 'index'])->middleware('permission:tickets.view,admin')->name('tickets.index');
    Route::get('tickets/feed', [TicketController::class, 'feed'])->middleware(['permission:tickets.view,admin', 'throttle:120,1'])->name('tickets.feed');
    Route::get('tickets/options', [TicketController::class, 'options'])->middleware('permission:tickets.view,admin')->name('tickets.options');
    Route::get('tickets/{id}', [TicketController::class, 'show'])->middleware('permission:tickets.view,admin')->whereNumber('id')->name('tickets.show');
    Route::get('tickets/{id}/attachments/{attachment}', [TicketController::class, 'download'])->middleware('permission:tickets.view,admin')->whereNumber(['id', 'attachment'])->name('tickets.attachment');
    Route::post('tickets/{id}/replies', [TicketController::class, 'reply'])->middleware(['permission:tickets.edit,admin', 'throttle:60,1'])->whereNumber('id')->name('tickets.reply');
    Route::put('tickets/{id}', [TicketController::class, 'update'])->middleware('permission:tickets.edit,admin')->whereNumber('id')->name('tickets.update');
    Route::post('tickets/{id}/assign', [TicketController::class, 'assign'])->middleware('permission:tickets.assign,admin')->whereNumber('id')->name('tickets.assign');

    // Departments: tickets.view reads, tickets.edit adds, changes and orders, tickets.delete removes one nobody has used.
    Route::get('ticket-departments', [TicketDepartmentController::class, 'index'])->middleware('permission:tickets.view,admin')->name('ticket-departments.index');
    Route::post('ticket-departments', [TicketDepartmentController::class, 'store'])->middleware('permission:tickets.edit,admin')->name('ticket-departments.store');
    Route::post('ticket-departments/reorder', [TicketDepartmentController::class, 'reorder'])->middleware('permission:tickets.edit,admin')->name('ticket-departments.reorder');
    Route::put('ticket-departments/{id}', [TicketDepartmentController::class, 'update'])->middleware('permission:tickets.edit,admin')->whereNumber('id')->name('ticket-departments.update');
    Route::delete('ticket-departments/{id}', [TicketDepartmentController::class, 'destroy'])->middleware('permission:tickets.delete,admin')->whereNumber('id')->name('ticket-departments.destroy');

    // Saved answers for replying to tickets: tickets.edit (the people who reply).
    Route::get('canned-replies', [CannedReplyController::class, 'index'])->middleware('permission:tickets.edit,admin')->name('canned-replies.index');
    Route::post('canned-replies', [CannedReplyController::class, 'store'])->middleware('permission:tickets.edit,admin')->name('canned-replies.store');
    Route::put('canned-replies/{id}', [CannedReplyController::class, 'update'])->middleware('permission:tickets.edit,admin')->whereNumber('id')->name('canned-replies.update');
    Route::delete('canned-replies/{id}', [CannedReplyController::class, 'destroy'])->middleware('permission:tickets.edit,admin')->whereNumber('id')->name('canned-replies.destroy');
});
