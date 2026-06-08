<?php

namespace App\Http\Middleware;

use App\Support\LaravelCacheHeader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AppendLaravelCacheHeader
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $status = LaravelCacheHeader::fromRequest($request);
        if ($status !== null) {
            $response->headers->set('X-Cache', $status);
        }

        return $response;
    }
}
