<?php

use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientSession;
use App\Models\ClientUser;
use App\Models\Role;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

// Shared by the feature tests (loaded from tests/Pest.php).

/** An admin holding exactly these permissions through a fresh role. */
function staffWith(array $permissions, string $roleName = 'Test Role'): Admin
{
    $role = Role::create(['name' => $roleName.' '.str()->random(5), 'guard_name' => 'admin']);
    $role->syncPermissions($permissions);

    return tap(Admin::factory()->create())->assignRole($role);
}

function superAdmin(): Admin
{
    return tap(Admin::factory()->create())->assignRole(Admin::SUPER_ADMIN_ROLE);
}

function api(Admin $admin)
{
    return test()->actingAs($admin, 'admin')->withHeaders(['Origin' => 'http://localhost:3003']);
}

function roleNamed(string $name): Role
{
    return Role::where('name', $name)->where('guard_name', 'admin')->firstOrFail();
}

// ---- client panel helpers

const CL_PASSWORD = 'Correct-Horse-9-Battery';
const CL_CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const CL_FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0';

/** Calls the API like the client panel does: from its origin, so the session middleware runs. */
function clSpa(string $userAgent = CL_CHROME_WINDOWS)
{
    return test()->withHeaders(['Origin' => 'http://localhost:3002', 'Referer' => 'http://localhost:3002/login', 'User-Agent' => $userAgent]);
}

function clRegisterBody(array $over = []): array
{
    return [
        'name' => 'Rahim Uddin', 'email' => 'rahim@uddintraders.com', 'phone' => '01711223344',
        'password' => CL_PASSWORD, 'password_confirmation' => CL_PASSWORD,
        'company' => 'Uddin Traders', 'accept_terms' => true, 'captcha_token' => 'token',
        ...$over,
    ];
}

function clRegister(array $over = [], string $userAgent = CL_CHROME_WINDOWS)
{
    return clSpa($userAgent)->postJson('/api/client/register', clRegisterBody($over));
}

function clLogin(string $email, string $password, string $userAgent = CL_CHROME_WINDOWS)
{
    return clSpa($userAgent)->postJson('/api/client/login', ['email' => $email, 'password' => $password, 'remember' => false]);
}

/** A client user with an account they own. */
function clUser(array $attributes = []): ClientUser
{
    $user = ClientUser::factory()->create($attributes);
    $account = ClientAccount::factory()->create(['owner_user_id' => $user->id, 'name' => 'Account of '.$user->name]);
    $user->forceFill(['last_account_id' => $account->id])->save();

    return $user;
}

/** The four values of a link, the way the client panel posts them. */
function clLinkValues(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $values);

    return $values;
}

/** Another signed-in browser of this person: a tracked session with some data in it. Returns its session id. */
function clOtherDevice(ClientUser $user, string $browser = 'Firefox', string $platform = 'Linux', int $minutesAgo = 5): string
{
    $id = Str::random(40);
    Session::driver()->getHandler()->write($id, serialize(['marker' => 'other device']));
    ClientSession::create([
        'client_user_id' => $user->id, 'session_id' => $id, 'ip_address' => '203.0.113.5', 'user_agent' => 'test',
        'browser' => $browser, 'platform' => $platform, 'last_active_at' => now()->subMinutes($minutesAgo),
    ]);

    return $id;
}

/** Whether that browser's session still exists. */
function clDeviceAlive(string $id): bool
{
    return Session::driver()->getHandler()->read($id) !== '';
}

/** Calls the API as `$user` from the browser with this session id. */
function clAs(ClientUser $user, ?string $sessionId = null, string $userAgent = CL_CHROME_WINDOWS)
{
    return clSpa($userAgent)->actingAs($user, 'client')->withCredentials()->withCookie(config('session.cookie'), $sessionId ?? Str::random(40));
}

/** The account a client user owns. */
function clAccountOf(ClientUser $user): ClientAccount
{
    return ClientAccount::where('owner_user_id', $user->id)->sole();
}
