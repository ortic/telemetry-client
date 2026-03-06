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
     * Get additional context data about the request.
     */
    protected function getContextData(): array
    {
        $context = [];

        try {
            if (!app()->runningInConsole()) {
                $context['method'] = request()->method();
                $context['ip'] = request()->ip();
            }

            // Include authenticated user ID if available
            if (auth()->check()) {
                $context['user_id'] = auth()->id();
                $context['user_email'] = auth()->user()->email ?? null;
            }
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
