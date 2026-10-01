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
 * The per-run caches live in worker-lifetime statics, so a Swoole worker that
 * runs the command in-process would otherwise carry them into the next run.
 * The command flushes them on the way in and on the way out. Hooks and tag
 * handlers are configuration and stay; {@see \Ipsocode\Scribe\Testing\TestState}
 * clears those too. See docs/design/run-state.md.
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
