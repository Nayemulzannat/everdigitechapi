<?php

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Route::middleware('api')->prefix('api/_test')->group(function () {
        Route::get('auth', fn () => abort(401))->middleware('auth:admin');
        Route::get('forbidden', fn () => Gate::authorize('nope'));
        Route::get('invalid', fn () => throw ValidationException::withMessages(['email' => ['The email is required.']]));
        Route::get('boom', fn () => throw new RuntimeException('secret db password'));
        Route::get('limited', fn () => 'ok')->middleware('throttle:1,1');
        Route::post('csrf', fn () => throw new TokenMismatchException);
    });
});

function expectErrorShape($response, int $status, string $code)
{
    $response->assertStatus($status)->assertJsonStructure(['message', 'code'])->assertJsonPath('code', $code);
}

it('formats 401', fn () => expectErrorShape($this->getJson('/api/_test/auth'), 401, 'unauthenticated'));
it('formats 403', fn () => expectErrorShape($this->getJson('/api/_test/forbidden'), 403, 'forbidden'));
it('formats 404 for unknown routes', fn () => expectErrorShape($this->getJson('/api/nothing-here'), 404, 'not_found'));
it('formats 404 even without an Accept header', fn () => expectErrorShape($this->get('/api/nothing-here'), 404, 'not_found'));
it('formats 405', fn () => expectErrorShape($this->postJson('/api/health'), 405, 'method_not_allowed'));

it('formats 422 with field errors', function () {
    expectErrorShape($this->getJson('/api/_test/invalid'), 422, 'validation_failed');
    $this->getJson('/api/_test/invalid')->assertJsonPath('errors.email.0', 'The email is required.');
});

it('formats 429', function () {
    $this->getJson('/api/_test/limited')->assertOk();
    expectErrorShape($this->getJson('/api/_test/limited'), 429, 'too_many_requests');
});

// Laravel skips the CSRF check while running tests, so the mismatch is thrown by the route instead.
it('formats 419 for a CSRF mismatch', fn () => expectErrorShape($this->postJson('/api/_test/csrf'), 419, 'csrf_token_mismatch'));

it('hides exception details when APP_DEBUG is false', function () {
    config(['app.debug' => false]);
    $response = $this->getJson('/api/_test/boom');
    expectErrorShape($response, 500, 'server_error');
    expect($response->getContent())->not->toContain('secret db password')->not->toContain('trace')->not->toContain('RuntimeException');
});

it('shows debug details only when APP_DEBUG is true', function () {
    config(['app.debug' => true]);
    $this->getJson('/api/_test/boom')->assertStatus(500)->assertJsonPath('debug.exception', RuntimeException::class);
});
