<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Tools;

use Ipsocode\Camel\BaseDTO;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Camel\Extraction\Parameter;
use Ipsocode\Scribe\Extracting\MethodAstParser;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\SeededFaker;
use Ipsocode\Scribe\Extracting\Shared\UrlParamsNormalizer;
use Ipsocode\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Reflection\DocBlock\Tag\AuthorTag;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\RunState;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Models\Post;

/**
 * What one `scribe:generate` run caches, and what it must leave alone.
 *
 * An application that regenerates its docs in-process — from a queued job, a
 * schedule or a request — keeps its Swoole worker afterwards, and with it every
 * static Scribe touched. The run's own caches have to go with the run; the
 * hooks the application registered at boot have to stay for the next one.
 */
class RunStateTest extends TestCase
{
    #[Test]
    public function flushDropsEveryPerRunCache(): void
    {
        $index = new ReflectionMethod(PostController::class, 'index');
        $showBound = new ReflectionMethod(PostController::class, 'showBound');

        $ast = MethodAstParser::getMethodAst($index);
        $classDocBlock = RouteDocBlocker::forClass(PostController::class);
        $methodDocBlock = RouteDocBlocker::forMethod($index);
        $faker = SeededFaker::get(1234);
        $models = UrlParamsNormalizer::getTypeHintedEloquentModels($showBound);
        new Parameter(['name' => 'id']);
        ConsoleOutputUtils::bootstrapOutput(new BufferedOutput);
        Globals::$shouldBeVerbose = true;

        (new GetFromLaravelAPI(new DocumentationConfig(config('scribe'))))(
            ExtractedEndpointData::fromRoute($this->workbenchRoute('blogs.show')),
        );
        $exampleRouteKeys = new ReflectionProperty(GetFromLaravelAPI::class, 'exampleRouteKeys');

        $this->assertInstanceOf(Post::class, $models['blog']);
        $this->assertNotSame([], $exampleRouteKeys->getValue());
        $this->assertSame($models, UrlParamsNormalizer::getTypeHintedEloquentModels($showBound));

        RunState::flush();

        $this->assertNotSame($ast, MethodAstParser::getMethodAst($index));
        $this->assertNotSame($classDocBlock, RouteDocBlocker::forClass(PostController::class));
        $this->assertNotSame($methodDocBlock, RouteDocBlocker::forMethod($index));
        $this->assertNotSame($faker, SeededFaker::get(1234));
        $this->assertNotSame($models, UrlParamsNormalizer::getTypeHintedEloquentModels($showBound));
        $this->assertSame([], (new ReflectionProperty(BaseDTO::class, 'propertyMetadata'))->getValue());
        $this->assertSame([], $exampleRouteKeys->getValue());
        $this->assertNull(ConsoleOutputUtils::getOutput());
        $this->assertFalse(Globals::$shouldBeVerbose);
    }

    #[Test]
    public function flushKeepsWhatTheApplicationConfiguredAtBoot(): void
    {
        $hook = fn (array $paths) => null;
        Scribe::afterGenerating($hook);
        Tag::registerTagHandler('scribe-run-state', AuthorTag::class);

        RunState::flush();

        $this->assertSame($hook, Globals::$__afterGenerating);
        $this->assertInstanceOf(AuthorTag::class, Tag::createInstance('@scribe-run-state Ada'));
    }
}
