<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces Sanctum's AuthenticateSession (see config/sanctum.php). That one compares the password hash of whoever is
 * signed in on the DEFAULT guard with the hashes stored for every guard, so one browser signed in to both the admin
 * panel and the client panel (staff using "log in as client", or both panels on localhost) is signed out on the next
 * request. This one checks each guard against its own user, and signs out only the guard whose password changed.
 */
class AuthenticateGuardSessions
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        foreach ($this->guards() as $name => $guard) {
            $stored = $request->session()->get("password_hash_{$name}");

            if ($stored !== null && ($user = $guard->user()) && ! $this->matches($guard, $user->getAuthPassword(), $stored)) {
                // Only this guard: the other panel's sign-in in the same browser is untouched.
                $guard->logoutCurrentDevice();
                $request->session()->forget("password_hash_{$name}");
            }
        }

        return tap($next($request), function () use ($request) {
            foreach ($this->guards() as $name => $guard) {
                if ($guard->hasUser()) {
                    $request->session()->put("password_hash_{$name}", $this->hash($guard, $guard->user()->getAuthPassword()));
                }
            }
        });
    }

    /** @return array<string, SessionGuard> */
    private function guards(): array
    {
        $guards = [];

        foreach (Arr::wrap(config('sanctum.guard')) as $name) {
            $guard = $this->auth->guard($name);

            if ($guard instanceof SessionGuard) {
                $guards[$name] = $guard;
            }
        }

        return $guards;
    }

    private function hash(SessionGuard $guard, ?string $passwordHash): string
    {
        return method_exists($guard, 'hashPasswordForCookie') ? $guard->hashPasswordForCookie($passwordHash) : (string) $passwordHash;
    }

    private function matches(SessionGuard $guard, ?string $passwordHash, string $stored): bool
    {
        return hash_equals($this->hash($guard, $passwordHash), $stored) || hash_equals((string) $passwordHash, $stored);
    }
}
