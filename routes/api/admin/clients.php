<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ImpersonationController;
use Illuminate\Support\Facades\Route;

// Clients (customer accounts). Served under /api/admin.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('clients', [ClientController::class, 'index'])->middleware('permission:clients.view,admin')->name('clients.index');

    Route::get('clients/{id}', [ClientController::class, 'show'])->whereNumber('id')->middleware('permission:clients.view,admin')->name('clients.show');
    Route::put('clients/{id}', [ClientController::class, 'update'])->whereNumber('id')->middleware('permission:clients.edit,admin')->name('clients.update');

    // "Log in as client": a one-minute link to the client panel. See App\Services\Impersonation.
    Route::post('clients/{account}/impersonate', [ImpersonationController::class, 'store'])->whereNumber('account')
        ->middleware(['permission:clients.login_as,admin', 'throttle:20,1'])->name('clients.impersonate');
});
