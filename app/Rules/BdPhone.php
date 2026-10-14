<?php

namespace App\Rules;

use App\Support\BdPhone as BdPhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Bangladeshi mobile number in any of the three accepted spellings. Use BdPhone::normalize() to store it. */
class BdPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! BdPhoneNumber::isValid($value)) {
            $fail('Enter a Bangladeshi mobile number, like 01711223344.');
        }
    }
}
