<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Refuses throwaway email services, listed in config/disposable_domains.php (a subdomain of a listed domain counts too). */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isDisposable($value)) {
            $fail('Use a permanent email address. Temporary email services are not accepted.');
        }
    }

    public static function isDisposable(string $email): bool
    {
        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === '') {
            return false;
        }

        $blocked = array_flip(array_map('strtolower', (array) config('disposable_domains.domains', [])));

        // "x.mailinator.com" is as disposable as "mailinator.com": test every parent domain.
        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            if (isset($blocked[implode('.', $parts)])) {
                return true;
            }
            array_shift($parts);
        }

        return false;
    }
}
