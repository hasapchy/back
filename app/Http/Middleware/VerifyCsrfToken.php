<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Http\Request;

class VerifyCsrfToken extends Middleware
{
    /**
     * @var array<int, string>
     */
    protected $except = [];

    /**
     * @param Request $request
     * @return string|null
     */
    protected function getTokenFromRequest($request)
    {
        $token = $request->input('_token');

        if (! is_string($token) || $token === '') {
            $header = $request->header('X-CSRF-TOKEN');
            $token = is_string($header) && $header !== '' ? trim($header) : '';
        }

        if ($token === '' && ($header = $request->header('X-XSRF-TOKEN'))) {
            if (! is_string($header) || $header === '') {
                return null;
            }
            $header = trim($header);
            try {
                $token = CookieValuePrefix::remove(
                    $this->encrypter->decrypt($header, static::serialized())
                );
            } catch (DecryptException) {
                $token = $header;
            }
        }

        return $token === '' ? null : $token;
    }
}
