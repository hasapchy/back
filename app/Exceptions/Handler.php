<?php

namespace App\Exceptions;

use App\Support\AuthRequestLogContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * @return void
     */
    public function register(): void
    {
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof AuthenticationException && $request->is('api/*')) {
            AuthRequestLogContext::logAuth('warning', 'auth.unauthenticated', array_merge(
                AuthRequestLogContext::fromRequest($request),
                ['path' => $request->path()]
            ));
        }

        return parent::render($request, $e);
    }
}
