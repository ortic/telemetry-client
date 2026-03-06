<?php

namespace Ortic\TelemetryClient\Facades;

use Illuminate\Support\Facades\Facade;
use Ortic\TelemetryClient\TelemetryClient;

/**
 * @method static bool reportException(\Throwable $exception, array $extra = [])
 *
 * @see \Ortic\TelemetryClient\TelemetryClient
 */
class Telemetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelemetryClient::class;
    }
}
