# Ortic Telemetry Client

A Laravel package that automatically captures and sends error telemetry data to your Ortic telemetry server. Works similarly to Sentry — install, configure two environment variables, and all unhandled exceptions are reported automatically.

## Installation

### 1. Add the package via Composer

If the package is hosted in a **private repository** (e.g. Bitbucket/GitHub), add the repository to your project's `composer.json` first:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@bitbucket.org:ortic/telemetry-client.git"
        }
    ]
}
```

Then install:

```bash
composer require ortic/telemetry-client
```

For **local development** with a path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../telemetry-client"
        }
    ]
}
```

```bash
composer require ortic/telemetry-client:@dev
```

### 2. Publish the config (optional)

```bash
php artisan vendor:publish --tag=telemetry-config
```

This creates `config/telemetry.php` where you can customize ignored exceptions, timeout, etc.

### 3. Configure environment variables

Add these to your `.env` file (get these values from the Ortic telemetry project setup wizard):

```env
TELEMETRY_DSN=your-dsn-token-here
TELEMETRY_ENDPOINT=https://your-ortic-instance.com/api/telemetry/ingest
```

That's it! All unhandled exceptions will now be reported automatically.

## Optional Configuration

```env
# Disable telemetry (e.g. for local dev)
TELEMETRY_ENABLED=false

# Override the environment name (defaults to APP_ENV)
TELEMETRY_ENVIRONMENT=staging

# Set a custom server name (defaults to hostname)
TELEMETRY_SERVER_NAME=web-01

# HTTP timeout in seconds (default: 5)
TELEMETRY_TIMEOUT=5
```

## Manual Reporting

You can also manually report exceptions or caught errors:

```php
use Ortic\TelemetryClient\Facades\Telemetry;

try {
    // risky operation
} catch (\Exception $e) {
    Telemetry::reportException($e, [
        'order_id' => $order->id,
        'payment_method' => 'stripe',
    ]);
}
```

## What Gets Sent

Each error report includes:

- **Exception class** and message
- **File and line** where the exception occurred
- **Stack trace** (up to 50 frames)
- **Request URL**, method, and user agent
- **Server name** and environment
- **Authenticated user** ID and email (if available)
- **Custom extra data** you attach manually

## Ignored Exceptions

By default, these exception types are **not** reported (configurable in `config/telemetry.php`):

- `NotFoundHttpException` (404s)
- `MethodNotAllowedHttpException` (405s)
- `ValidationException`
- `AuthenticationException`

## How It Works

1. The package registers a singleton `TelemetryClient` via Laravel's service container
2. During boot, it hooks into Laravel's exception handler using `reportable()`
3. When an unhandled exception occurs, the client builds a JSON payload and sends it via HTTP POST to your telemetry endpoint
4. The telemetry server groups errors by fingerprint (`sha256(class + message + file + line)`) and tracks frequency
5. All HTTP errors are caught silently — **telemetry will never break your application**

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- Guzzle 7+
