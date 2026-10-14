<?php

namespace App\Http\Resources;

use App\Models\Admin;
use App\Models\ClientUser;
use App\Services\AccountContext;
use App\Services\AvatarProcessor;
use App\Services\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/client/me, and what register, login and PUT /current-account return. Field names are snake_case, as the
 * client panel's contract asks. Owners have every permission, shown as ["*"].
 *
 * @property ClientUser $resource
 */
class ClientMeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $context = app(AccountContext::class);

        return [
            'user' => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'email_verified' => $user->hasVerifiedEmail(),
                'phone_verified' => $user->hasVerifiedPhone(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'avatar_url' => app(AvatarProcessor::class)->url($user->avatar_path),
            ],
            'accounts' => $context->memberships($user)->map(fn ($membership) => [
                'id' => (string) $membership->account_id,
                'name' => $membership->account->name,
                'role' => $membership->role->value,
                'permissions' => $membership->isOwner() ? ['*'] : array_values((array) ($membership->permissions ?? [])),
                'is_owner' => $membership->isOwner(),
            ])->values()->all(),
            'current_account_id' => ($id = $context->resolve($user)?->account_id) !== null ? (string) $id : null,
            // Set while a staff member is signed in as this client ("log in as client"); null for a normal session.
            'impersonation' => $this->impersonation($request),
        ];
    }

    /**
     * Laravel answers 201 for a resource whose model was created during the same PHP process, which would make /me and
     * login look like a "created" answer right after sign-up. Only register says 201 (it sets that itself, after this).
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }

    /** @return array{admin_name: string, started_at: ?string, expires_at: ?string}|null */
    private function impersonation(Request $request): ?array
    {
        $impersonation = app(Impersonation::class);
        $adminId = $impersonation->adminId($request);
        $name = $adminId ? Admin::query()->whereKey($adminId)->value('name') : null;

        if (! $name) {
            return null;
        }

        $started = $impersonation->startedAt($request);

        return [
            'admin_name' => $name,
            'started_at' => $started?->toIso8601ZuluString(),
            'expires_at' => $started?->copy()->addMinutes(Impersonation::SESSION_MINUTES)->toIso8601ZuluString(),
        ];
    }
}
