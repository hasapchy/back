<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    protected function isApiRequest(Request $request): bool
    {
        return $request->is('api/*');
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Validation\ValidationException  $exception
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception): JsonResponse
    {
        if ($this->isApiRequest($request)) {
            return response()->json([
                'error' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        }

        return parent::invalidJson($request, $exception);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if ($this->isApiRequest($request)) {
            return response()->json(['error' => $exception->getMessage()], 401);
        }

        return parent::unauthenticated($request, $exception);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Illuminate\Http\JsonResponse
     */
    protected function prepareJsonResponse($request, Throwable $e): JsonResponse
    {
        if ($this->isApiRequest($request)) {
            $status = 500;
            $headers = [];
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $headers = $e->getHeaders();
            }
            $message = $e instanceof HttpExceptionInterface ? $e->getMessage() : 'Server Error';
            $payload = ['error' => $message];

            if (config('app.debug') && !$e instanceof HttpExceptionInterface) {
                $payload['exception'] = get_class($e);
                $payload['file'] = $e->getFile();
                $payload['line'] = $e->getLine();
            }

            return new JsonResponse(
                $payload,
                $status,
                $headers,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return parent::prepareJsonResponse($request, $e);
    }
}
