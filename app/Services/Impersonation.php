<?php

namespace App\Services;

use App\Enums\AdminStatus;
use App\Enums\ClientStatus;
use App\Enums\MemberStatus;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Models\ImpersonationToken;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * "Log in as client". Staff create a one-time token (60 seconds, stored hashed); the client panel trades it for a
 * client-guard session that carries `impersonated_by_admin_id` and `impersonation_started_at`, and ends after 30 minutes.
 */
class Impersonation
{
    public const TOKEN_SECONDS = 60;

    public const SESSION_MINUTES = 30;

    private const KEY_ADMIN = 'impersonated_by_admin_id';

    private const KEY_STARTED = 'impersonation_started_at';

    public function __construct(private readonly ActivityLogger $activity) {}

    /** Staff side. Returns the URL for the client panel. */
    public function issue(Admin $admin, ClientAccount $account, ?int $clientUserId): string
    {
        if ($account->status === ClientStatus::Closed) {
            throw $this->closed();
        }

        $user = ClientUser::find($clientUserId ?? $account->owner_user_id);

        if (! $user || ! $this->isActiveMember($account, $user)) {
            throw new ApiException(422, 'not_a_member', 'That person is not an active member of this account.');
        }

        if ($user->isSuspended()) {
            throw new ApiException(422, 'client_suspended', 'This client user is suspended.');
        }

        $token = Str::random(64);
        ImpersonationToken::create([
            'token_hash' => hash('sha256', $token),
            'admin_id' => $admin->getKey(),
            'client_user_id' => $user->getKey(),
            'account_id' => $account->getKey(),
            'expires_at' => now()->addSeconds(self::TOKEN_SECONDS),
        ]);

        return config('everdigitech.client_url').'/impersonate?token='.$token;
    }

    /** Client side: trades the token for a signed-in session. */
    public function consume(Request $request, string $plainToken): ClientUser
    {
        $invalid = new ApiException(422, 'invalid_impersonation_token', 'This sign-in link is invalid or has expired.');

        $token = ImpersonationToken::where('token_hash', hash('sha256', $plainToken))->first();
        if (! $token || $token->used_at || $token->expires_at->isPast()) {
            throw $invalid;
        }

        // Only one request can win this update, so the token works once even under concurrent use.
        $claimed = ImpersonationToken::whereKey($token->getKey())->whereNull('used_at')->update(['used_at' => now()]);
        if ($claimed !== 1) {
            throw $invalid;
        }

        $admin = Admin::find($token->admin_id);
        $account = ClientAccount::find($token->account_id);
        $user = ClientUser::find($token->client_user_id);

        // Things can change in 60 seconds, and the staff member may have lost the right meanwhile.
        if (! $admin || $admin->status !== AdminStatus::Active || ! $admin->can('clients.login_as')) {
            throw $invalid;
        }
        if (! $account || $account->status === ClientStatus::Closed) {
            throw $this->closed();
        }
        if (! $user || $user->isSuspended() || ! $this->isActiveMember($account, $user)) {
            throw $invalid;
        }

        Auth::guard('client')->login($user);
        $request->session()->regenerate();
        $request->session()->put([self::KEY_ADMIN => $admin->getKey(), self::KEY_STARTED => now()->getTimestamp()]);

        // The account staff asked for is the one that opens. The person's own last_login_* are left alone.
        $user->forceFill(['last_account_id' => $account->getKey()])->save();

        ClientLoginHistory::create([
            'client_user_id' => $user->getKey(),
            'email_attempted' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            ...UserAgent::parse($request->userAgent()),
            'successful' => true,
            'impersonated_by_admin_id' => $admin->getKey(),
        ]);

        $this->activity->log('client.impersonation_started', $user, $this->properties($admin, $user, $account->getKey()), $admin);

        return $user;
    }

    public function isActive(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::KEY_ADMIN);
    }

    public function adminId(Request $request): ?int
    {
        return $this->isActive($request) ? (int) $request->session()->get(self::KEY_ADMIN) : null;
    }

    public function startedAt(Request $request): ?Carbon
    {
        $at = $this->isActive($request) ? $request->session()->get(self::KEY_STARTED) : null;

        return $at ? Carbon::createFromTimestamp((int) $at) : null;
    }

    public function expired(Request $request): bool
    {
        if (! $this->isActive($request)) {
            return false;
        }

        $started = $this->startedAt($request);

        return ! $started || $started->addMinutes(self::SESSION_MINUTES)->isPast();
    }

    /**
     * Ends the impersonation: signs out the client guard only (a staff sign-in in the same browser stays) and writes
     * `client.impersonation_ended`. Does nothing when this session is not an impersonation.
     */
    public function end(Request $request, string $reason): void
    {
        if (! $this->isActive($request)) {
            return;
        }

        $admin = Admin::find($this->adminId($request));
        $user = $request->user('client');

        $this->activity->log('client.impersonation_ended', $user, [
            ...$this->properties($admin, $user, $user?->last_account_id),
            'reason' => $reason,
        ], $admin);

        $request->session()->forget([self::KEY_ADMIN, self::KEY_STARTED]);
        Auth::guard('client')->logout();
        $request->session()->regenerateToken();
    }

    private function isActiveMember(ClientAccount $account, ClientUser $user): bool
    {
        return $account->members()->where('user_id', $user->getKey())->where('status', MemberStatus::Active->value)->exists();
    }

    private function closed(): ApiException
    {
        return new ApiException(422, 'account_closed', 'This account is closed, so you cannot sign in as it.');
    }

    /** @return array<string, mixed> */
    private function properties(?Admin $admin, ?ClientUser $user, ?int $accountId): array
    {
        return [
            'admin_id' => $admin?->getKey(),
            'admin_name' => $admin?->name,
            'client_user_id' => $user?->getKey(),
            'client_email' => $user?->email,
            'account_id' => $accountId,
        ];
    }
}
