<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/client/current-account */
class SwitchAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['account_id' => ['required', 'integer', 'min:1']];
    }
}
