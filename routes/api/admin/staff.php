<?php

use App\Http\Controllers\Admin\StaffController;
use Illuminate\Support\Facades\Route;

// Staff accounts. Served under /api/admin. Each route needs the matching staff.* permission.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('staff', [StaffController::class, 'index'])->middleware('permission:staff.view,admin')->name('staff.index');
    // Before staff/{id}, or "roles" would be read as an id.
    Route::get('staff/roles', [StaffController::class, 'roles'])->middleware('permission:staff.view,admin')->name('staff.roles');
    Route::get('staff/{id}', [StaffController::class, 'show'])->middleware('permission:staff.view,admin')->name('staff.show');
    Route::post('staff', [StaffController::class, 'store'])->middleware('permission:staff.create,admin')->name('staff.store');
    Route::put('staff/{id}', [StaffController::class, 'update'])->middleware('permission:staff.edit,admin')->name('staff.update');
    Route::delete('staff/{id}', [StaffController::class, 'destroy'])->middleware('permission:staff.delete,admin')->name('staff.destroy');
    Route::post('staff/{id}/resend-invite', [StaffController::class, 'resendInvite'])
        ->middleware(['permission:staff.create,admin', 'throttle:10,1'])->name('staff.resend-invite');
});
