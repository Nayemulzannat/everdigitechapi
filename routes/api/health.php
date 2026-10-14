<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DomainSearchController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// GET /api/health
Route::get('/health', HealthController::class)->name('health');

// GET /api/catalog: the products and prices for the public site and the order form. No sign-in.
Route::get('/catalog', CatalogController::class)->middleware('throttle:60,1')->name('catalog');

// The public domain search of the website (no sign-in). Few requests a minute: every check is a call to the registrar.
Route::get('/domains/extensions', [DomainSearchController::class, 'extensions'])->name('domains.extensions');
Route::get('/domains/search', [DomainSearchController::class, 'search'])->middleware('throttle:30,1')->name('domains.search');
