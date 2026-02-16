<?php

namespace App\Http\Middleware;

use Closure;
use ErrorException;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class PreventRequestsDuringMaintenance extends Middleware
{
    protected $except = [];

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($this->inExceptArray($request)) {
            return $next($request);
        }

        if (!$this->app->maintenanceMode()->active()) {
            return $next($request);
        }

        try {
            $data = $this->app->maintenanceMode()->data();
        } catch (ErrorException $e) {
            if (!$this->app->maintenanceMode()->active()) {
                return $next($request);
            }
            throw $e;
        }

        if ($this->canBypass($request, $data)) {
            return $next($request);
        }

        if (isset($data['secret']) && $request->path() === $data['secret']) {
            return $this->bypassResponse($data['secret']);
        }

        if ($this->hasValidBypassCookie($request, $data)) {
            return $next($request);
        }

        if (isset($data['redirect'])) {
            $path = $data['redirect'] === '/' ? $data['redirect'] : trim($data['redirect'], '/');
            if ($request->path() !== $path) {
                return redirect($path);
            }
        }

        if (isset($data['template'])) {
            return response(
                $data['template'],
                $data['status'] ?? 503,
                $this->getHeaders($data)
            );
        }

        throw new \Symfony\Component\HttpKernel\Exception\HttpException(
            $data['status'] ?? 503,
            'Service Unavailable',
            null,
            $this->getHeaders($data)
        );
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $data
     * @return bool
     */
    protected function canBypass($request, array $data): bool
    {
        $secret = $data['secret'] ?? null;

        if ($secret === null) {
            return false;
        }

        $bypassHeader = $request->header('X-Maintenance-Bypass');

        if ($bypassHeader === null) {
            $bypassHeader = $request->headers->get('X-Maintenance-Bypass');
        }

        if ($bypassHeader === null) {
            $bypassHeader = $request->headers->get('x-maintenance-bypass');
        }

        if (app()->environment('local')) {
            \Log::info('Maintenance bypass check', [
                'secret' => $secret,
                'header_value' => $bypassHeader,
                'all_headers' => $request->headers->all(),
            ]);
        }

        return $bypassHeader !== null && $bypassHeader === $secret;
    }
}
