<?php

use App\Http\Controllers\Client\Account\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Two-factor authentication of the signed-in client. Served under /api/client. (The sign-in step is POST /two-factor-challenge.)

Route::middleware(['auth:client', 'client.active', 'client.session'])->group(function () {
    Route::get('two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');

    Route::middleware(['client.not_impersonating', 'throttle:client-sensitive'])->group(function () {
        Route::post('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
        Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
        Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
    });
});
