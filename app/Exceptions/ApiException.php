<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An HTTP error with its own machine-readable code, e.g. new ApiException(403, 'account_suspended', 'This account is suspended.').
 * `$extra` is merged into the JSON body next to message and code.
 */
class ApiException extends HttpException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(int $status, public readonly string $errorCode, string $message, array $headers = [], public readonly array $extra = [])
    {
        parent::__construct($status, $message, null, $headers);
    }
}
