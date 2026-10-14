<?php

namespace App\Services;

use App\Models\ClientSession;
use App\Models\ClientUser;
use App\Support\UserAgent;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session as SessionFacade;
use Illuminate\Support\Str;

/**
 * Keeps `client_sessions` (one row per signed-in client browser) up to date and can sign browsers out.
 *
 * Signing out a browser means destroying its Laravel session, so everything in it ends, including a staff sign-in that
 * happens to share that browser. That is the price of being sure the client sign-in is gone. (Laravel's
 * AuthenticateSession middleware does the same through session()->flush(), and would also check on every request.)
 */
class ClientSessionTracker
{
    /** Writes the current browser's row only this often when nothing changed, so a busy page isn't a write per request. */
    private const REFRESH_AFTER_SECONDS = 60;

    /** Called when someone signs in or registers (after the session id has been regenerated). */
    public function start(ClientUser $user, Request $request): void
    {
        if ($request->hasSession()) {
            $this->touch($user, $request, force: true);
        }
    }

    /** Called on every authenticated client request: this browser is this user, and was just active. */
    public function touch(ClientUser $user, Request $request, bool $force = false): void
    {
        $sessionId = $request->session()->getId();
        $agent = UserAgent::parse($request->userAgent());
        $row = ClientSession::query()->where('session_id', $sessionId)->first();

        if ($row
            && ! $force
            && $row->client_user_id === $user->getKey()
            && $row->ip_address === $request->ip()
            && $row->last_active_at->gt(now()->subSeconds(self::REFRESH_AFTER_SECONDS))) {
            return;
        }

        ClientSession::query()->updateOrCreate(['session_id' => $sessionId], [
            // A browser can switch from one client user to another: the row follows the session.
            'client_user_id' => $user->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'browser' => $agent['browser'],
            'platform' => $agent['platform'],
            'last_active_at' => now(),
        ]);
    }

    /** Called on sign-out: this browser is no longer a signed-in client. */
    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            ClientSession::query()->where('session_id', $request->session()->getId())->delete();
        }
    }

    /** The browsers this person is signed in on and that were active within the session lifetime. */
    public function activeFor(ClientUser $user)
    {
        return ClientSession::query()
            ->where('client_user_id', $user->getKey())
            ->where('last_active_at', '>=', now()->subMinutes((int) config('session.lifetime', 120)))
            ->orderByDesc('last_active_at')
            ->orderByDesc('id');
    }

    /**
     * Signs the person out of every browser except the one making this request: their sessions are destroyed and their
     * rows removed. The remember-me token is replaced too, so a "keep me signed in" cookie elsewhere stops working.
     * Returns how many browsers were signed out.
     */
    public function revokeOthers(ClientUser $user, ?Session $current): int
    {
        $query = ClientSession::query()->where('client_user_id', $user->getKey());
        if ($current) {
            $query->where('session_id', '!=', $current->getId());
        }

        return $this->destroy($user, $query->pluck('session_id')->all());
    }

    /** Signs the person out of every browser, this one too (after a password reset, when nobody is signed in). */
    public function revokeAll(ClientUser $user): int
    {
        return $this->revokeOthers($user, null);
    }

    /** @param  list<string>  $sessionIds */
    private function destroy(ClientUser $user, array $sessionIds): int
    {
        $handler = SessionFacade::driver()->getHandler();
        foreach ($sessionIds as $id) {
            $handler->destroy($id);
        }
        ClientSession::query()->whereIn('session_id', $sessionIds)->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        return count($sessionIds);
    }
}
