<?php

namespace App\Http\Requests\Client;

use App\Support\AccountPermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /api/client/team/members/{id} {permissions?, status?}: change what a member may do, or suspend / reactivate them. */
class UpdateMemberRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permissions' => ['sometimes', 'required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(AccountPermissionCatalog::keys())],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
        ];
    }

    public function messages(): array
    {
        return ['permissions.min' => 'Choose at least one thing this person may do. To remove their access, remove them from the team.'];
    }
}
