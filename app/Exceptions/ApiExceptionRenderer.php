<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns every exception into { "message": string, "code": string, "errors"?: { field: string[] } }.
 * Registered in bootstrap/app.php. Runs after Laravel's own mapping, so a policy denial arrives as a 403
 * HttpException, a missing model as a 404, and a CSRF mismatch as a 419.
 */
class ApiExceptionRenderer
{
    /** Status → [machine code, message] for errors that are the same wherever they come from. */
    private const KNOWN = [
        401 => ['unauthenticated', 'You are not signed in.'],
        403 => ['forbidden', "You don't have permission to do that."],
        404 => ['not_found', "That item doesn't exist or was removed."],
        405 => ['method_not_allowed', 'This method is not allowed here.'],
        419 => ['csrf_token_mismatch', 'Your session expired. Try again.'],
        429 => ['too_many_requests', 'Too many attempts. Wait a moment and try again.'],
    ];

    public function __invoke(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return $this->respond(422, 'validation_failed', $e->getMessage(), $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return $this->respond(401, 'unauthenticated', self::KNOWN[401][1]);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$code, $message] = self::KNOWN[$status] ?? ($status < 500
                ? ['http_error', 'The request could not be completed.']
                : ['server_error', 'Something went wrong on our side.']);

            // An exception can carry its own machine code and message (e.g. 423 "suspended", 429 "locked").
            if ($e instanceof ApiException) {
                [$code, $message] = [$e->errorCode, $e->getMessage()];
            }

            $response = $this->respond($status, $code, $message, null, $e->getHeaders(), $e);

            return $e instanceof ApiException && $e->extra
                ? $response->setData([...$response->getData(true), ...$e->extra])
                : $response;
        }

        return $this->respond(500, 'server_error', 'Something went wrong on our side.', null, [], $e);
    }

    /** @param  array<string, string[]>|null  $errors */
    private function respond(int $status, string $code, string $message, ?array $errors = null, array $headers = [], ?Throwable $e = null): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        // Debug details only with APP_DEBUG=true; never in production.
        if ($e !== null && config('app.debug') && $status >= 500) {
            $body['message'] = $e->getMessage() ?: $message;
            $body['debug'] = [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect($e->getTrace())->take(15)->map(fn ($f) => ($f['file'] ?? '').':'.($f['line'] ?? ''))->all(),
            ];
        }

        return new JsonResponse($body, $status, $headers);
    }
}
