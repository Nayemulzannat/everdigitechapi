<?php

namespace App\Providers;

use App\Models\AccountMember;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Services\GeoIp\GeoIpLookup;
use App\Services\GeoIp\NullGeoIpLookup;
use App\Services\Registrar\DynadotRegistrar;
use App\Services\Registrar\Registrar;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The registrar the domain code talks to. Swap this line to use another one.
        $this->app->bind(Registrar::class, DynadotRegistrar::class);
        // No GeoIP database yet: bind a real lookup here when there is one.
        $this->app->bind(GeoIpLookup::class, NullGeoIpLookup::class);
    }

    public function boot(): void
    {
        // Every date in a JSON response is ISO 8601 in UTC: 2026-10-03T08:15:00Z.
        Carbon::serializeUsing(fn (CarbonInterface $date) => $date->copy()->utc()->toIso8601ZuluString());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Client sign-up: 5 per IP per hour. The verification email can be re-sent once a minute and 6 times an hour.
        RateLimiter::for('client-register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('client-verification-resend', fn (Request $request) => [
            Limit::perMinute(1)->by('m:'.$request->user('client')?->getAuthIdentifier()),
            Limit::perHour(6)->by('h:'.$request->user('client')?->getAuthIdentifier()),
        ]);

        // Client account security: reset requests per IP, and the actions that check a password (change it, change the
        // email, sign out other devices) per person, so a stolen session can't be used to guess the password.
        RateLimiter::for('client-forgot-ip', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('client-sensitive', fn (Request $request) => Limit::perMinute(5)->by($request->user('client')?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('client-email-change', fn (Request $request) => Limit::perHour(3)->by('ec:'.($request->user('client')?->getAuthIdentifier() ?: $request->ip())));
        RateLimiter::for('client-ticket-open', fn (Request $request) => Limit::perHour(10)->by('to:'.($request->user('client')?->getAuthIdentifier() ?: $request->ip())));
        RateLimiter::for('client-ticket-reply', fn (Request $request) => Limit::perHour(30)->by('tr:'.($request->user('client')?->getAuthIdentifier() ?: $request->ip())));

        // After `account.context`: the account this request is about, and the signed-in client's membership of it.
        Request::macro('currentAccount', fn (): ?ClientAccount => $this->attributes->get('current_account'));
        Request::macro('currentMembership', fn (): ?AccountMember => $this->attributes->get('current_membership'));

        // The Super Admin passes every permission check, whatever permissions it holds.
        Gate::before(fn ($user) => $user instanceof Admin && $user->isSuperAdmin() ? true : null);

        // Staff passwords: 12+ characters, mixed case, a number, and not in a known data breach.
        // The breach lookup calls haveibeenpwned.com, so it is skipped while running tests.
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()
            ->when(! $this->app->runningUnitTests(), fn (Password $rule) => $rule->uncompromised()));
    }
}
