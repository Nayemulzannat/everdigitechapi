<?php

use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use Illuminate\Support\Facades\Route;

// The catalog: what the company sells and at what price. Served under /api/admin.
// products.view reads, products.create adds, products.edit changes, products.delete removes.

Route::middleware(['auth:admin', 'admin.active'])->group(function () {
    Route::get('product-groups', [ProductGroupController::class, 'index'])->middleware('permission:products.view,admin')->name('product-groups.index');
    Route::post('product-groups', [ProductGroupController::class, 'store'])->middleware('permission:products.create,admin')->name('product-groups.store');
    Route::put('product-groups/{id}', [ProductGroupController::class, 'update'])->middleware('permission:products.edit,admin')->whereNumber('id')->name('product-groups.update');
    Route::delete('product-groups/{id}', [ProductGroupController::class, 'destroy'])->middleware('permission:products.delete,admin')->whereNumber('id')->name('product-groups.destroy');

    Route::get('products', [ProductController::class, 'index'])->middleware('permission:products.view,admin')->name('products.index');
    Route::get('products/options', [ProductController::class, 'options'])->middleware('permission:products.view,admin')->name('products.options');
    Route::get('products/{id}', [ProductController::class, 'show'])->middleware('permission:products.view,admin')->whereNumber('id')->name('products.show');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.create,admin')->name('products.store');
    Route::put('products/{id}', [ProductController::class, 'update'])->middleware('permission:products.edit,admin')->whereNumber('id')->name('products.update');
    Route::delete('products/{id}', [ProductController::class, 'destroy'])->middleware('permission:products.delete,admin')->whereNumber('id')->name('products.destroy');
});
