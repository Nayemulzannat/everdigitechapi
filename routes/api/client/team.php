<?php

use App\Http\Controllers\Client\Account\TeamController;
use App\Http\Controllers\Client\Account\TeamInvitationController;
use Illuminate\Support\Facades\Route;

// The people of an account: the owner invites, changes and removes them. Served under /api/client.

// The invited person: look at the invitation (public, the token is the secret), then accept it while signed in.
Route::get('team/invitation', [TeamInvitationController::class, 'show'])->middleware('throttle:20,1')->name('team.invitation');

Route::middleware(['auth:client', 'client.active', 'client.session'])->group(function () {
    Route::post('team/invitations/accept', [TeamInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('team.accept');

    // Needs a current account (X-Account-Id header, or the last used one).
    Route::middleware('account.context')->group(function () {
        // A member may leave on their own. Not while staff are signed in as that member.
        Route::post('team/leave', [TeamController::class, 'leave'])->middleware('client.not_impersonating')->name('team.leave');

        Route::middleware('account.owner')->group(function () {
            Route::get('team', [TeamController::class, 'index'])->name('team.index');

            // Inviting and removing people: never while staff are signed in as the client.
            Route::middleware('client.not_impersonating')->group(function () {
                Route::post('team/invitations', [TeamController::class, 'invite'])->middleware(['client.verified', 'throttle:20,60'])->name('team.invite');
                Route::post('team/invitations/{id}/resend', [TeamController::class, 'resend'])->middleware(['client.verified', 'throttle:20,60'])->whereNumber('id')->name('team.resend');
                Route::delete('team/invitations/{id}', [TeamController::class, 'cancel'])->whereNumber('id')->name('team.cancel');
                Route::put('team/members/{id}', [TeamController::class, 'update'])->whereNumber('id')->name('team.members.update');
                Route::delete('team/members/{id}', [TeamController::class, 'remove'])->whereNumber('id')->name('team.members.remove');
            });
        });
    });
});
