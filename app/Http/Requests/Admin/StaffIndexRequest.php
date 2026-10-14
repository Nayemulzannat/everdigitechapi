<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /staff?page=&role=&status=&search= ("all" and empty values mean no filter). */
class StaffIndexRequest extends FormRequest
{
    public const STATUSES = ['active', 'invited', 'suspended'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'role' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'admin')],
            'status' => ['sometimes', Rule::in(['all', ...self::STATUSES])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
