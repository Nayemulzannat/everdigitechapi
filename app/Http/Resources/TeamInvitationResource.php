<?php

namespace App\Http\Resources;

use App\Models\AccountInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An open invitation, as the owner sees it. The token is never shown: only the email has it. @property AccountInvitation $resource */
class TeamInvitationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $invitation = $this->resource;

        return [
            'id' => (string) $invitation->getKey(),
            'email' => $invitation->email,
            'permissions' => array_values((array) $invitation->permissions),
            'invited_by' => $invitation->inviter?->name,
            'created_at' => $invitation->created_at?->toIso8601ZuluString(),
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
        ];
    }
}
