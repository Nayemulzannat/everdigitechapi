<?php

namespace App\Http\Requests\Client;

use App\Rules\BdPhone;
use App\Support\BdPhone as BdPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/client/profile {name, phone, avatar?, remove_avatar?}. A file cannot travel in a PUT body, so the panel sends
 * multipart as POST with `_method=PUT` (or plain POST: both work).
 */
class UpdateProfileRequest extends FormRequest
{
    public const AVATAR_MAX_KB = 2048;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new BdPhone],
            // mimes looks at the file's content, not its name. 8000px is far above any phone photo and keeps GD's memory in check.
            'avatar' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::AVATAR_MAX_KB, 'dimensions:max_width=8000,max_height=8000'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.mimes' => 'The picture must be a JPG, PNG or WebP file.',
            'avatar.max' => 'The picture must be 2 MB or smaller.',
            'avatar.uploaded' => 'The picture could not be uploaded. It may be larger than 2 MB.',
            'avatar.dimensions' => 'The picture is too large in pixels (8000 x 8000 at most).',
        ];
    }

    /** The phone as it is stored: +8801XXXXXXXXX. */
    public function phone(): string
    {
        return (string) BdPhoneNumber::normalize($this->validated('phone'));
    }
}
