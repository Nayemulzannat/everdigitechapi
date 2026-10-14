<?php

use App\Http\Controllers\Client\Auth\EmailVerificationController;
use App\Http\Controllers\Client\Auth\GoogleLoginController;
use App\Http\Controllers\Client\Auth\ImpersonationController;
use App\Http\Controllers\Client\Auth\LoginController;
use App\Http\Controllers\Client\Auth\MeController;
use App\Http\Controllers\Client\Auth\PasswordResetController;
use App\Http\Controllers\Client\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

// Customer sign-up, sign-in and who-am-I. Served under /api/client.

Route::post('register', RegisterController::class)->middleware('throttle:client-register')->name('register');
Route::post('login', [LoginController::class, 'login'])->name('login');
// "Continue with Google": the browser sends the Google ID token (credential); the API checks it with Google.
Route::post('google', GoogleLoginController::class)->middleware('throttle:10,1')->name('google');
// Second step of a sign-in with two-factor on (the password step started it in this session).
Route::post('two-factor-challenge', [LoginController::class, 'twoFactorChallenge'])->middleware('throttle:10,1')->name('two-factor.challenge');
// Second step of a sign-in with two-factor on (the password step started it in this session).
Route::post('two-factor-challenge', [LoginController::class, 'twoFactorChallenge'])->middleware('throttle:10,1')->name('two-factor.challenge');
Route::post('logout', [LoginController::class, 'logout'])->name('logout');

// "Forgot password": 3 per email per hour (counted in the controller) and 10 a minute per IP. The link leads to the client panel.
Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:client-forgot-ip')->name('password.forgot');
Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');

// The link in the email leads to the client panel, which posts its four values here. No sign-in needed.
Route::post('email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:20,1')->name('verification.verify');

// Staff "log in as client": the panel trades the one-time token from the admin panel for a session.
Route::post('impersonate/consume', [ImpersonationController::class, 'consume'])->middleware('throttle:10,1')->name('impersonate.consume');

Route::middleware(['auth:client', 'client.active', 'client.session'])->group(function () {
    Route::post('impersonate/exit', [ImpersonationController::class, 'exit'])->name('impersonate.exit');

    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:client-verification-resend')->name('verification.send');

    Route::get('me', [MeController::class, 'show'])->name('me');
    Route::put('current-account', [MeController::class, 'switchAccount'])->name('current-account');
});
