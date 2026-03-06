<?php

namespace Ortic\TelemetryClient;

use Illuminate\Contracts\Debug\ExceptionHandler;
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
                $handler->reportable(function (Throwable $e) {
                    $this->app->make(TelemetryClient::class)->reportException($e);
                })->stop(false); // Don't stop other reporters (e.g. log)
            }
        } catch (Throwable $e) {
            // Silently fail — don't break the app if handler setup fails
        }
    }
}
