<?php

namespace App\Http\Controllers\Client\Auth;

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\RegisterRequest;
use App\Http\Resources\ClientMeResource;
use App\Models\AccountMember;
use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Services\ClientSessionTracker;
use App\Support\UserAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    /** POST /api/client/register */
    public function __invoke(RegisterRequest $request, ClientSessionTracker $sessions): JsonResponse
    {
        // One transaction: a person with no account (or an account with no owner) must never be left behind.
        $user = DB::transaction(function () use ($request) {
            $user = ClientUser::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->phone(),
                'password' => $request->validated('password'),
            ]);

            $company = $request->validated('company');
            $account = ClientAccount::create([
                'name' => $company ?: $user->name,
                'company' => $company,
                'owner_user_id' => $user->getKey(),
                'client_group' => ClientGroup::Regular,
                'status' => ClientStatus::Active,
            ]);

            AccountMember::create([
                'account_id' => $account->getKey(),
                'user_id' => $user->getKey(),
                'role' => MemberRole::Owner,
                'status' => MemberStatus::Active,
                'joined_at' => now(),
            ]);

            $user->forceFill(['last_account_id' => $account->getKey()])->save();

            return $user;
        });

        // After the commit, and queued: the mail server is not part of signing up.
        $user->sendEmailVerificationNotification();

        Auth::guard('client')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $sessions->start($user, $request);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $agent = UserAgent::parse($request->userAgent());
        ClientLoginHistory::create([
            'client_user_id' => $user->getKey(),
            'email_attempted' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            ...$agent,
            'successful' => true,
        ]);

        return (new ClientMeResource($user))->response()->setStatusCode(201);
    }
}
