<?php

use App\Http\Controllers\Admin\MeController;
use Illuminate\Support\Facades\Route;

// The signed-in admin. Served under /api/admin.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('me', [MeController::class, 'show'])->name('me');
    Route::get('me/login-history', [MeController::class, 'loginHistory'])->name('me.login-history');
});
