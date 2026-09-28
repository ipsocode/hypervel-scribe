<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Ipsocode\Camel\BaseDTO;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\MethodAstParser;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\SeededFaker;
use Ipsocode\Scribe\Extracting\Shared\UrlParamsNormalizer;
use Ipsocode\Scribe\Extracting\Strategies\GetFromFormRequestBase;
use Ipsocode\Scribe\Extracting\Strategies\GetFromInlineValidatorBase;
use Ipsocode\Scribe\Extracting\Strategies\Responses\UseApiResourceTags;
use Ipsocode\Scribe\Extracting\Strategies\Responses\UseResponseAttributes;
use Ipsocode\Scribe\Extracting\Strategies\Responses\UseTransformerTags;
use Ipsocode\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI;

/**
 * The state one `scribe:generate` run builds up, which nothing after it should see.
 *
 * Scribe keeps its per-run caches — parsed ASTs and docblocks, reflected DTO
 * metadata, the example Faker, cached example models and rows, the console it
 * reports to — in worker-lifetime statics. A CLI process takes them with it
 * when it exits. A long-lived Swoole worker that runs the command in-process
 * (`Artisan::call()` from a queued job, a scheduled task or a request) does
 * not, so without this a second run would read the ASTs of controllers that
 * have changed since the first, keep the first run's Eloquent models alive, and
 * report through a command that has already finished.
 *
 * The command flushes this on the way in and on the way out.
 *
 * Deliberately absent: the hooks an application registers through the
 * `Scribe` class, and the docblock tag handlers. Those are configuration, set
 * once when the application boots, and a run must not erase them.
 * {@see \Ipsocode\Scribe\Testing\TestState} clears those too, on top of this.
 */
final class RunState
{
    /**
     * Reset every per-run cache and the console binding.
     */
    public static function flush(): void
    {
        Globals::$shouldBeVerbose = false;
        ConsoleOutputUtils::flushState();
        Extractor::flushState();
        MethodAstParser::flushState();
        RouteDocBlocker::flushState();
        UrlParamsNormalizer::flushState();
        SeededFaker::flushState();
        BaseDTO::flushState();
        UseResponseAttributes::flushState();
        UseApiResourceTags::flushState();
        UseTransformerTags::flushState();
        GetFromFormRequestBase::flushState();
        GetFromInlineValidatorBase::flushState();
        GetFromLaravelAPI::flushState();
    }
}
