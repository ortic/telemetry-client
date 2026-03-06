<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telemetry DSN Token
    |--------------------------------------------------------------------------
    |
    | The DSN token used to authenticate with the telemetry server.
    | Get this from the telemetry project setup in your Ortic dashboard.
    |
    */
    'dsn' => env('TELEMETRY_DSN', ''),

    /*
    |--------------------------------------------------------------------------
    | Telemetry Endpoint
    |--------------------------------------------------------------------------
    |
    | The full URL of the telemetry ingestion endpoint.
    | Example: https://your-ortic-instance.com/api/telemetry/ingest
    |
    */
    'endpoint' => env('TELEMETRY_ENDPOINT', ''),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable telemetry reporting. You may want to disable this
    | in local development environments.
    |
    */
    'enabled' => env('TELEMETRY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The environment name sent with each error report.
    | Defaults to the Laravel APP_ENV value.
    |
    */
    'environment' => env('TELEMETRY_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Server Name
    |--------------------------------------------------------------------------
    |
    | A human-readable name for this server instance.
    | Useful when running multiple servers behind a load balancer.
    |
    */
    'server_name' => env('TELEMETRY_SERVER_NAME', gethostname()),

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | Exception classes listed here will NOT be reported to telemetry.
    | Useful for ignoring expected exceptions like 404s or validation errors.
    |
    */
    'ignored_exceptions' => [
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | HTTP timeout in seconds for sending telemetry data.
    | Keep this low to avoid slowing down your application.
    |
    */
    'timeout' => env('TELEMETRY_TIMEOUT', 5),
];
