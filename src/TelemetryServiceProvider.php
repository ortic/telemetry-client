<?php

namespace Ortic\TelemetryClient;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Throwable;

class TelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register the telemetry client as a singleton.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/telemetry.php', 'telemetry');

        $this->app->singleton(TelemetryClient::class, function ($app) {
            return new TelemetryClient($app['config']->get('telemetry', []));
        });
    }

    /**
     * Bootstrap the telemetry integration.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/telemetry.php' => config_path('telemetry.php'),
        ], 'telemetry-config');

        // Hook into Laravel's exception handler to auto-report exceptions
        $this->registerExceptionReporting();

        // Conditionally register performance tracing middleware
        $this->registerTracingMiddleware();
    }

    /**
     * Register automatic exception reporting via Laravel's exception handler.
     */
    protected function registerExceptionReporting(): void
    {
        try {
            $handler = $this->app->make(ExceptionHandler::class);

            // Laravel 11+ uses reportable() on the handler
            if (method_exists($handler, 'reportable')) {
                // No ->stop(): that would stop the other reporters (the log in particular) for every
                // exception, even one telemetry didn't take. Returning false stops them for this one.
                $handler->reportable(function (Throwable $e) {
                    $reported = $this->app->make(TelemetryClient::class)->reportException($e);

                    // With stop_other_reporters, an exception the server accepted goes no further
                    if ($reported && $this->app['config']->get('telemetry.stop_other_reporters', false)) {
                        return false;
                    }
                });
            }
        } catch (Throwable $e) {
            // Silently fail — don't break the app if handler setup fails
        }
    }

    /**
     * Register the tracing middleware when tracing is enabled.
     */
    protected function registerTracingMiddleware(): void
    {
        if (!$this->app['config']->get('telemetry.tracing.enabled', false)) {
            return;
        }

        try {
            $kernel = $this->app->make(Kernel::class);

            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(TelemetryTracingMiddleware::class);
            }
        } catch (Throwable $e) {
            // Silently fail — tracing should never break the app
        }
    }
}

