<?php

namespace App\Http\Requests\Client;

use App\Rules\BdPhone;
use App\Rules\NotDisposableEmail;
use App\Services\CaptchaVerifier;
use App\Support\BdPhone as BdPhoneNumber;
use App\Support\PasswordRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/client/register */
class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $company = is_string($this->input('company')) ? trim($this->input('company')) : $this->input('company');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'company' => $company === '' ? null : $company,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The unique index also covers deleted people, so they count too.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('client_users', 'email'), new NotDisposableEmail],
            'phone' => ['required', 'string', new BdPhone],
            'password' => ['required', 'string', 'confirmed', PasswordRules::client()],
            'company' => ['nullable', 'string', 'max:255'],
            'accept_terms' => ['accepted'],
            'captcha_token' => ['nullable', 'string', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists. Try signing in.',
            'accept_terms.accepted' => 'You must accept the terms to create an account.',
        ];
    }

    /** The Turnstile check runs last, and only for an otherwise valid form, so a typo doesn't use up the one-time token. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && ! app(CaptchaVerifier::class)->verify($this->input('captcha_token'), $this->ip())) {
                    $validator->errors()->add('captcha_token', 'Please complete the security check and try again.');
                }
            },
        ];
    }

    /** The phone as it is stored: +8801XXXXXXXXX. */
    public function phone(): string
    {
        return (string) BdPhoneNumber::normalize($this->validated('phone'));
    }
}
