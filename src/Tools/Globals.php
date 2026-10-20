<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Closure;

class Globals
{
    public static bool $shouldBeVerbose = false;

    // Hooks an application registers through the Scribe class.

    public static ?Closure $__beforeResponseCall = null;

    public static ?Closure $__afterResponseCall = null;

    public static ?Closure $__bootstrap = null;

    public static ?Closure $__afterGenerating = null;

    public static ?Closure $__instantiateFormRequestUsing = null;

    public static ?Closure $__normalizeEndpointUrlUsing = null;

    public static ?Closure $__afterExtracting = null;

    public static ?Closure $__resolveExampleModelUsing = null;

    public static function flushState(): void
    {
        self::$shouldBeVerbose = false;
        self::$__beforeResponseCall = null;
        self::$__afterResponseCall = null;
        self::$__bootstrap = null;
        self::$__afterGenerating = null;
        self::$__instantiateFormRequestUsing = null;
        self::$__normalizeEndpointUrlUsing = null;
        self::$__afterExtracting = null;
        self::$__resolveExampleModelUsing = null;
    }
}
