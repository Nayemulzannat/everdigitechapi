<?php

namespace App\Http\Resources;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The frontend's StaffMember (types/staff.ts). Load `roles` and `latestLogin` first to avoid one query per row,
 * and call StaffController::withSuspendedBy() for the "suspended by" name.
 *
 * @property Admin $resource
 */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $this->resource;
        $role = $admin->roles->sortBy('id')->first();
        $login = $admin->relationLoaded('latestLogin') ? $admin->latestLogin : null;

        return [
            'id' => (string) $admin->getKey(),
            'name' => $admin->name,
            'email' => $admin->email,
            'phone' => $admin->phone,
            'avatarUrl' => $admin->avatar_path ? Storage::disk('public')->url($admin->avatar_path) : null,
            // active | invited (no password set yet) | suspended
            'status' => $admin->displayStatus(),
            'role' => $role ? [
                'id' => (string) $role->getKey(),
                'name' => $role->name,
                'isSuperAdmin' => $role->name === Admin::SUPER_ADMIN_ROLE,
            ] : null,
            'invitedAt' => $admin->invited_at,
            'lastLoginAt' => $admin->last_login_at,
            'lastLoginIp' => $admin->last_login_ip,
            // "Chrome on Windows"; null when unknown. There is no location: the API has no GeoIP lookup.
            'lastLoginDevice' => self::device($login?->browser, $login?->platform),
            // Name of the admin who suspended this person, when the activity log has it.
            'suspendedBy' => $admin->getAttribute('suspended_by'),
            'twoFactorEnabled' => $admin->hasTwoFactorEnabled(),
            'createdAt' => $admin->created_at,
        ];
    }

    private static function device(?string $browser, ?string $platform): ?string
    {
        return match (true) {
            $browser && $platform => "{$browser} on {$platform}",
            default => $browser ?: $platform,
        };
    }
}
