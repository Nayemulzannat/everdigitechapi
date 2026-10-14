<?php

namespace App\Http\Resources;

use App\Models\AccountMember;
use App\Services\AvatarProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A person of the account, as the owner sees them on the Team page. @property AccountMember $resource */
class TeamMemberResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $member = $this->resource;
        $user = $member->user;

        return [
            'id' => (string) $member->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => app(AvatarProcessor::class)->url($user->avatar_path),
            'role' => $member->role->value,
            'status' => $member->status->value,
            'permissions' => $member->isOwner() ? ['*'] : array_values((array) ($member->permissions ?? [])),
            'joined_at' => $member->joined_at?->toIso8601ZuluString(),
            'is_you' => $user->getKey() === $request->user('client')?->getKey(),
        ];
    }
}
