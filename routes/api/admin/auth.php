<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\PasswordResetController;
use Illuminate\Support\Facades\Route;

// Sign-in, sign-out and password reset. Served under /api/admin.

Route::post('login', [AuthController::class, 'login'])->name('login');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('throttle:5,1')->group(function () {
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
});
