<?php

use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use Illuminate\Support\Facades\Route;

// Roles and the permission catalog. Served under /api/admin. Each route needs the matching permission.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view,admin')->name('permissions.index');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view,admin')->name('roles.index');
    Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:roles.view,admin')->name('roles.show');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create,admin')->name('roles.store');
    Route::put('roles/{id}', [RoleController::class, 'update'])->middleware('permission:roles.edit,admin')->name('roles.update');
    Route::delete('roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete,admin')->name('roles.destroy');
});
