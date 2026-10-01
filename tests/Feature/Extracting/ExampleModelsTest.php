<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Strategies\Responses\UseApiResourceTags;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Models\Post;

/**
 * Where the example model behind an API-resource or transformer response comes
 * from: the first strategy in `examples.models_source` that produces a model.
 * The default list starts with `factoryCreate`, so the others rarely run unless
 * an application puts them first; they are pinned here because a silent
 * fallback to an empty model documents every field as null.
 */
class ExampleModelsTest extends DatabaseTestCase
{
    private function body(string $routeName): array
    {
        $endpoint = (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));

        return json_decode($this->firstResponse($endpoint)['content'], true);
    }

    private function firstResponse(ExtractedEndpointData $endpoint): array
    {
        $responses = $endpoint->responses->toArray();

        $this->assertNotEmpty($responses, 'No response was extracted.');

        return $responses[0];
    }

    #[Test]
    public function factoryMakeBuildsTheExampleWithoutWritingIt(): void
    {
        config(['scribe.examples.models_source' => ['factoryMake']]);

        $data = $this->body('posts.show')['data'];

        $this->assertNotEmpty($data['title']);
        // `make()` rather than `create()`: nothing reached the table, so the
        // model has no id to show either.
        $this->assertNull($data['id']);
        $this->assertSame(0, Post::query()->count());
    }

    #[Test]
    public function databaseFirstDocumentsARowThatIsAlreadyThere(): void
    {
        config(['scribe.examples.models_source' => ['databaseFirst']]);
        Post::factory()->create(['title' => 'The row on disk']);

        $this->assertSame('The row on disk', $this->body('posts.show')['data']['title']);
    }

    #[Test]
    public function theApplicationCanChooseTheRowItself(): void
    {
        // The bare "first row" query has no ORDER BY and no way to say "the row
        // that should stand for *this* endpoint", which is what the hook is for.
        config(['scribe.examples.models_source' => ['databaseFirst']]);
        Post::factory()->create(['title' => 'Whichever comes first']);
        $chosen = Post::factory()->create(['title' => 'The one worth showing']);

        Scribe::resolveExampleModelUsing(fn (string $type) => $type::query()->find($chosen->id));

        $this->assertSame('The one worth showing', $this->body('posts.show')['data']['title']);
    }

    #[Test]
    public function returningNothingFromTheHookFallsThroughLikeAnEmptyTable(): void
    {
        config(['scribe.examples.models_source' => ['databaseFirst', 'factoryMake']]);
        Scribe::resolveExampleModelUsing(fn () => null);

        // Nothing thrown, nothing warned about — the next strategy just runs.
        $this->assertNotEmpty($this->body('posts.show')['data']['title']);
    }

    #[Test]
    public function aStrategyThatThrowsIsWarnedAboutAndAnEmptyModelStandsIn(): void
    {
        $buffer = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($buffer);

        config(['scribe.examples.models_source' => ['databaseFirst']]);
        Scribe::resolveExampleModelUsing(fn () => throw new RuntimeException('The example query blew up.'));

        $data = $this->body('posts.show')['data'];

        $this->assertStringContainsString("Couldn't get example model for", $buffer->fetch());
        // Every configured strategy failed, so the response is rendered from a
        // bare instance — documented, but with nothing in it.
        $this->assertNull($data['id']);
        $this->assertNull($data['title']);
    }

    #[Test]
    public function noConfiguredSeedLeavesTheFakerAlone(): void
    {
        // The seed is what makes example models reproducible across runs; an
        // application that wants fresh data every run sets it to null, and the
        // factories then run off whatever the framework seeded.
        //
        // The seeded-this-run guard is a static the command resets at the start
        // and end of each run. Extracting directly skips that, so a test that
        // wants to watch the first model of a run resets it itself.
        UseApiResourceTags::flushState();

        config(['scribe.examples.faker_seed' => null]);

        $this->assertNotEmpty($this->body('posts.show')['data']['title']);

        $seeded = new ReflectionProperty(UseApiResourceTags::class, 'factoryFakerSeeded');
        $this->assertFalse($seeded->getValue(), 'The faker was seeded despite no seed being configured.');
    }
}
