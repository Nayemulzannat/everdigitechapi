<?php

use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\DomainExtensionController;
use App\Http\Controllers\Admin\DomainPricingController;
use Illuminate\Support\Facades\Route;

// Domains of the clients, and the extensions we sell. Served under /api/admin.
// domains.view reads, domains.create adds a domain by hand, domains.edit changes (extension prices too), domains.delete removes.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('domain-extensions', [DomainExtensionController::class, 'index'])->middleware('permission:domains.view,admin')->name('domain-extensions.index');
    Route::post('domain-extensions/sync', [DomainExtensionController::class, 'sync'])->middleware(['permission:domains.edit,admin', 'throttle:6,1'])->name('domain-extensions.sync');
    Route::post('domain-extensions/activate-priced', [DomainExtensionController::class, 'activatePriced'])->middleware('permission:domains.edit,admin')->name('domain-extensions.activate-priced');
    Route::get('domain-pricing', [DomainPricingController::class, 'show'])->middleware('permission:domains.view,admin')->name('domain-pricing.show');
    Route::put('domain-pricing', [DomainPricingController::class, 'update'])->middleware('permission:domains.edit,admin')->name('domain-pricing.update');
    Route::post('domain-extensions', [DomainExtensionController::class, 'store'])->middleware('permission:domains.edit,admin')->name('domain-extensions.store');
    Route::put('domain-extensions/{id}', [DomainExtensionController::class, 'update'])->middleware('permission:domains.edit,admin')->whereNumber('id')->name('domain-extensions.update');
    Route::delete('domain-extensions/{id}', [DomainExtensionController::class, 'destroy'])->middleware('permission:domains.delete,admin')->whereNumber('id')->name('domain-extensions.destroy');

    Route::get('domains', [DomainController::class, 'index'])->middleware('permission:domains.view,admin')->name('domains.index');
    Route::get('domains/{id}', [DomainController::class, 'show'])->middleware('permission:domains.view,admin')->whereNumber('id')->name('domains.show');
    Route::post('domains', [DomainController::class, 'store'])->middleware('permission:domains.create,admin')->name('domains.store');
    Route::put('domains/{id}', [DomainController::class, 'update'])->middleware('permission:domains.edit,admin')->whereNumber('id')->name('domains.update');
    Route::delete('domains/{id}', [DomainController::class, 'destroy'])->middleware('permission:domains.delete,admin')->whereNumber('id')->name('domains.destroy');
});
