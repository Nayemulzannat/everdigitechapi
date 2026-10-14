<?php

use App\Http\Controllers\Admin\TaxSettingsController;
use Illuminate\Support\Facades\Route;

// Business settings. Served under /api/admin. settings.view reads, settings.edit changes.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('settings/tax', [TaxSettingsController::class, 'show'])->middleware('permission:settings.view,admin')->name('settings.tax.show');
    Route::put('settings/tax', [TaxSettingsController::class, 'update'])->middleware('permission:settings.edit,admin')->name('settings.tax.update');
});
