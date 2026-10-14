<?php

use App\Http\Controllers\Client\Account\EmailChangeController;
use App\Http\Controllers\Client\Account\LoginHistoryController;
use App\Http\Controllers\Client\Account\PasswordController;
use App\Http\Controllers\Client\Account\ProfileController;
use App\Http\Controllers\Client\Account\SessionController;
use Illuminate\Support\Facades\Route;

// What a signed-in customer can do about their own login: password, profile, email, devices, history. Served under /api/client.

// The link goes to the NEW address and is opened from that mailbox, so it needs no sign-in.
Route::post('email/change/confirm', [EmailChangeController::class, 'confirm'])->middleware('throttle:20,1')->name('email-change.confirm');

Route::middleware(['auth:client', 'client.active', 'client.session'])->group(function () {
    Route::put('password', [PasswordController::class, 'update'])->middleware(['client.not_impersonating', 'throttle:client-sensitive'])->name('password.update');

    // POST too: a file can't be sent in a PUT body, so the panel posts multipart with _method=PUT (or just POST).
    Route::match(['put', 'post'], 'profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::post('email/change', [EmailChangeController::class, 'request'])->middleware(['client.not_impersonating', 'throttle:client-sensitive', 'throttle:client-email-change'])->name('email-change.request');

    Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('sessions/others', [SessionController::class, 'destroyOthers'])->middleware(['client.not_impersonating', 'throttle:client-sensitive'])->name('sessions.destroy-others');

    Route::get('login-history', [LoginHistoryController::class, 'index'])->name('login-history');
});
