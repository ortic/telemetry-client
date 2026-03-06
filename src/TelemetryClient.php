<?php

namespace Ortic\TelemetryClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelemetryClient
{
    protected Client $http;
    protected string $dsn;
    protected string $endpoint;
    protected string $environment;
    protected string $serverName;
    protected array $ignoredExceptions;
    protected bool $enabled;
    protected array $reportedExceptions = [];

    public function __construct(array $config = [])
    {
        $this->dsn = $config['dsn'] ?? '';
        $this->endpoint = $config['endpoint'] ?? '';
        $this->enabled = $config['enabled'] ?? true;
        $this->environment = $config['environment'] ?? 'production';
        $this->serverName = $config['server_name'] ?? gethostname();
        $this->ignoredExceptions = $config['ignored_exceptions'] ?? [];

        $this->http = new Client([
            'timeout' => $config['timeout'] ?? 5,
            'connect_timeout' => 3,
        ]);
    }

    /**
     * Report an exception to the telemetry server.
     */
    public function reportException(Throwable $exception, array $extra = []): bool
    {
        if (!$this->shouldReport($exception)) {
            return false;
        }

        // Deduplicate: skip if this exact exception object was already reported
        $exceptionId = spl_object_id($exception);
        if (in_array($exceptionId, $this->reportedExceptions, true)) {
            return false;
        }
        $this->reportedExceptions[] = $exceptionId;

        $payload = $this->buildPayload($exception, $extra);

        return $this->send($payload);
    }

    /**
     * Determine if the exception should be reported.
     */
    protected function shouldReport(Throwable $exception): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (empty($this->dsn) || empty($this->endpoint)) {
            return false;
        }

        foreach ($this->ignoredExceptions as $ignoredClass) {
            if ($exception instanceof $ignoredClass) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the JSON payload for the telemetry server.
     */
    protected function buildPayload(Throwable $exception, array $extra = []): array
    {
        return [
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'level' => 'error',
            'trace' => $this->formatTrace($exception),
            'server_name' => $this->serverName,
            'environment' => $this->environment,
            'url' => $this->getCurrentUrl(),
            'user_agent' => $this->getUserAgent(),
            'extra' => array_merge($this->getContextData(), $extra),
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Format the exception stack trace into a structured array.
     */
    protected function formatTrace(Throwable $exception): array
    {
        $frames = [];

        // Add the exception origin as the first frame
        $frames[] = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'function' => '',
            'class' => get_class($exception),
        ];

        // Add stack trace frames
        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? '[internal]',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? '',
                'class' => $frame['class'] ?? '',
            ];
        }

        // Limit to 50 frames to keep payload size reasonable
        return array_slice($frames, 0, 50);
    }

    /**
     * Get the current request URL, if available.
     */
    protected function getCurrentUrl(): ?string
    {
        try {
            if (app()->runningInConsole()) {
                return 'console://' . implode(' ', $_SERVER['argv'] ?? []);
            }
            return request()->fullUrl();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get the user agent from the current request.
     */
    protected function getUserAgent(): ?string
    {
        try {
            return request()->userAgent();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get comprehensive context data about the request and environment.
     */
    protected function getContextData(): array
    {
        $context = [];

        try {
            if (!app()->runningInConsole()) {
                $request = request();

                // HTTP request info
                $context['request'] = [
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'route' => $request->route()?->getName() ?? $request->route()?->getActionName() ?? null,
                    'ip' => $request->ip(),
                    'ips' => $request->ips(),
                ];

                // Request headers (filter out sensitive ones)
                $sensitiveHeaders = ['authorization', 'cookie', 'x-csrf-token', 'x-xsrf-token'];
                $headers = [];
                foreach ($request->headers->all() as $key => $values) {
                    if (in_array(strtolower($key), $sensitiveHeaders)) {
                        $headers[$key] = '[REDACTED]';
                    } else {
                        $headers[$key] = count($values) === 1 ? $values[0] : $values;
                    }
                }
                $context['headers'] = $headers;

                // Request body (truncated for safety, skip file uploads)
                if (!$request->isMethod('GET') && !$request->hasFile('file')) {
                    $body = $request->except(['password', 'password_confirmation', 'token', 'secret', '_token']);
                    $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
                    if ($encoded && strlen($encoded) <= 8192) {
                        $context['body'] = $body;
                    } else {
                        $context['body'] = '[TRUNCATED - ' . strlen($encoded) . ' bytes]';
                    }
                }

                // Query parameters
                if ($request->query()) {
                    $context['query'] = $request->query();
                }

                // Client info
                $context['client'] = [
                    'user_agent' => $request->userAgent(),
                    'content_type' => $request->header('Content-Type'),
                    'accept' => $request->header('Accept'),
                    'referer' => $request->header('Referer'),
                    'origin' => $request->header('Origin'),
                ];

                // Session info (if available)
                try {
                    if ($request->hasSession()) {
                        $context['session_id'] = substr($request->session()->getId(), 0, 8) . '...';
                    }
                } catch (Throwable $e) {
                    // No session available
                }
            } else {
                // Console context
                $context['console'] = [
                    'command' => implode(' ', $_SERVER['argv'] ?? []),
                ];
            }

            // Authenticated user
            if (auth()->check()) {
                $context['user'] = [
                    'id' => auth()->id(),
                    'email' => auth()->user()->email ?? null,
                    'name' => auth()->user()->name ?? null,
                ];
            }

            // Runtime info
            $context['runtime'] = [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'memory_usage' => round(memory_get_peak_usage(true) / 1024 / 1024, 1) . ' MB',
            ];
        } catch (Throwable $e) {
            // Silently ignore context collection failures
        }

        return $context;
    }

    /**
     * Send the payload to the telemetry server.
     */
    protected function send(array $payload): bool
    {
        try {
            $response = $this->http->post($this->endpoint, [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->dsn,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

            return $response->getStatusCode() === 201;
        } catch (GuzzleException $e) {
            // Log locally but don't throw — telemetry should never break the app
            Log::warning('Telemetry: Failed to send error report: ' . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            Log::warning('Telemetry: Unexpected error: ' . $e->getMessage());
            return false;
        }
    }
}
