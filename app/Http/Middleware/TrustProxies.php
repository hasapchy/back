<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * @return void
     */
    public function __construct()
    {
        if (app()->environment('local')) {
            $this->proxies = '*';

            return;
        }
        $raw = env('TRUSTED_PROXIES');
        if ($raw === null || $raw === '') {
            return;
        }
        if ($raw === '*') {
            $this->proxies = '*';

            return;
        }
        $this->proxies = array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @var array<int, string>|string|null
     */
    protected $proxies;

    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
