<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

class Globals
{
    public static bool $shouldBeVerbose = false;

    // Hooks an application registers through the Scribe class.

    public static $__beforeResponseCall;

    public static $__afterResponseCall;

    public static $__bootstrap;

    public static $__afterGenerating;

    public static $__instantiateFormRequestUsing;

    public static $__normalizeEndpointUrlUsing;

    public static $__afterExtracting;

    public static $__resolveExampleModelUsing;

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
