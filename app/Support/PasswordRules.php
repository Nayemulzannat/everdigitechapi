<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** Password rules per kind of account. Staff passwords (Password::defaults()) are stricter than customers'. */
class PasswordRules
{
    /** Customers: 10+ characters, upper and lower case, a number, and not found in a known data breach. */
    public static function client(): Password
    {
        // The breach lookup calls haveibeenpwned.com, so it is skipped while running tests (as for staff).
        return Password::min(10)->mixedCase()->numbers()
            ->when(! app()->runningUnitTests(), fn (Password $rule) => $rule->uncompromised());
    }
}
