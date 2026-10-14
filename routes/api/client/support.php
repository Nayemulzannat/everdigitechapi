<?php

use App\Http\Controllers\Client\Account\TicketController;
use Illuminate\Support\Facades\Route;

// Support tickets of the current account. Served under /api/client.
// tickets.view: read. tickets.create: open, reply, close (and not while staff are signed in as the client:
// a reply would look like it came from the client).

Route::middleware(['auth:client', 'client.active', 'client.session', 'account.context'])->group(function () {
    Route::middleware('account.permission:tickets.view')->group(function () {
        Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/feed', [TicketController::class, 'feed'])->middleware('throttle:120,1')->name('tickets.feed');
        Route::get('tickets/{id}', [TicketController::class, 'show'])->whereNumber('id')->name('tickets.show');
        Route::get('tickets/{id}/attachments/{attachment}', [TicketController::class, 'download'])->whereNumber(['id', 'attachment'])->name('tickets.attachment');
    });

    Route::middleware(['account.permission:tickets.create', 'client.not_impersonating'])->group(function () {
        Route::get('ticket-departments', [TicketController::class, 'departments'])->name('tickets.departments');
        Route::post('tickets', [TicketController::class, 'store'])->middleware(['client.verified', 'throttle:client-ticket-open'])->name('tickets.store');
        Route::post('tickets/{id}/replies', [TicketController::class, 'reply'])->middleware('throttle:client-ticket-reply')->whereNumber('id')->name('tickets.reply');
        Route::post('tickets/{id}/close', [TicketController::class, 'close'])->whereNumber('id')->name('tickets.close');
    });
});
