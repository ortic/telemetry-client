<?php

namespace Ortic\TelemetryClient;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelemetryTracingMiddleware
{
    protected TelemetryClient $client;
    protected array $spans = [];
    protected float $startTime;
    protected bool $listening = false;

    public function __construct(TelemetryClient $client)
    {
        $this->client = $client;
    }

    /**
     * Handle an incoming request — capture DB queries as spans.
     */
    public function handle(Request $request, Closure $next)
    {
        $config = config('telemetry.tracing', []);

        // Check if tracing is enabled
        if (empty($config['enabled'])) {
            return $next($request);
        }

        // Apply sample rate
        $sampleRate = $config['sample_rate'] ?? 1.0;
        if ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
            return $next($request);
        }

        $this->startTime = microtime(true);
        $this->spans = [];

        // Listen to DB queries
        $this->listening = true;
        DB::listen(function ($query) {
            if (!$this->listening) {
                return;
            }

            $now = microtime(true);
            $this->spans[] = [
                'op' => 'db.query',
                'description' => $query->sql,
                'start_offset_ms' => round(($now - $query->time / 1000 - $this->startTime) * 1000, 2),
                'duration_ms' => round($query->time, 2),
                'data' => [
                    'bindings_count' => count($query->bindings),
                    'connection' => $query->connectionName,
                ],
            ];
        });

        $response = $next($request);

        // Stop listening before we send the transaction (to avoid capturing our own queries)
        $this->listening = false;

        $this->sendTransaction($request, $response);

        return $response;
    }

    /**
     * Build and send the transaction payload.
     */
    protected function sendTransaction(Request $request, $response): void
    {
        $endTime = microtime(true);
        $durationMs = round(($endTime - $this->startTime) * 1000, 2);

        // Skip transactions below the minimum duration threshold
        $minDuration = config('telemetry.tracing.min_duration_ms', 0);
        if ($minDuration > 0 && $durationMs < $minDuration) {
            return;
        }

        // Determine transaction name from route
        $route = $request->route();
        $name = 'unknown';
        if ($route) {
            $name = $route->getName()
                ?? $route->getActionName()
                ?? $request->method() . ' ' . $request->path();
        }

        $payload = [
            'trace_id' => (string) Str::uuid(),
            'name' => $name,
            'op' => 'http.request',
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'environment' => config('telemetry.environment', config('app.env', 'production')),
            'server_name' => config('telemetry.server_name', gethostname()),
            'occurred_at' => now()->toIso8601String(),
            'spans' => $this->spans,
        ];

        // Fire-and-forget: send asynchronously to not block the response
        try {
            $this->client->sendTransaction($payload);
        } catch (\Throwable $e) {
            // Never break the app for telemetry
        }
    }
}
