<?php

namespace App\Http\Middleware;

use App\Models\SystemLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one system_logs row per API / storefront / webhook request — method,
 * path, status, duration. The simple, clear operation trail for everything the
 * theme and Shopify call. Request bodies are NOT logged (they may carry tokens /
 * PII); only the outcome. 5xx logs at error, 4xx at warning, otherwise info.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $status = $response->getStatusCode();
        $level = match (true) {
            $status >= 500 => SystemLog::LEVEL_ERROR,
            $status >= 400 => SystemLog::LEVEL_WARNING,
            default => SystemLog::LEVEL_INFO,
        };

        SystemLog::write(
            $level,
            $this->category($request),
            $request->method().' /'.ltrim($request->path(), '/').' → '.$status,
            [],
            [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status_code' => $status,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'ip' => $request->ip(),
            ],
        );

        return $response;
    }

    private function category(Request $request): string
    {
        return match (true) {
            $request->is('storefront/*') => 'storefront',
            $request->is('*/webhook/*', 'shopify/webhooks') => 'webhook',
            $request->is('api/cron/*', 'order/cron/*') => 'cron',
            $request->is('api/orders/*', 'order', 'order/*') => 'billing',
            $request->is('shopify/*') => 'shopify',
            default => 'api',
        };
    }
}
