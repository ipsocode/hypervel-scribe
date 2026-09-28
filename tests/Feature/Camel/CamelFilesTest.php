<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Camel;

use Hypervel\Support\Facades\File;
use Ipsocode\Camel\Camel;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

/**
 * Reading the intermediate `.scribe/endpoints` YAML back. This is what
 * `--no-extraction` renders from and what a re-run merges user edits against,
 * so the order the group files come back in is the order the docs are in.
 *
 * It lives in the Feature suite because the loaders go through the framework's
 * filesystem.
 */
class CamelFilesTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        // Absolute, and outside the working directory, the way an absolute
        // `--scribe-dir` would be.
        $this->folder = sys_get_temp_dir() . '/scribe-camel-files-' . getmypid();
        File::deleteDirectory($this->folder);
        File::makeDirectory($this->folder, 0o777, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    private function writeGroup(string $fileName, string $groupName): void
    {
        File::put("{$this->folder}/{$fileName}", Yaml::dump([
            'name' => $groupName,
            'description' => '',
            'endpoints' => [['uri' => "api/{$groupName}"]],
        ]));
    }

    #[Test]
    public function groupFilesComeBackInNaturalNameOrderWhateverOrderTheyWereWrittenIn(): void
    {
        // Written out of order, and past the two digits the writer pads to.
        $this->writeGroup('100.yaml', 'Hundredth');
        $this->writeGroup('01.yaml', 'Second');
        $this->writeGroup('99.yaml', 'Ninety-ninth');
        $this->writeGroup('00.yaml', 'First');

        $this->assertSame(
            ['First', 'Second', 'Ninety-ninth', 'Hundredth'],
            array_keys(Camel::loadEndpointsIntoGroups($this->folder)),
        );
        $this->assertSame(
            ['api/First', 'api/Second', 'api/Ninety-ninth', 'api/Hundredth'],
            array_column(Camel::loadEndpointsToFlatPrimitivesArray($this->folder), 'uri'),
        );
    }

    #[Test]
    public function customEndpointFilesAreReadAsUserDefinedEndpointsAndNotAsGroups(): void
    {
        $this->writeGroup('00.yaml', 'Posts');
        File::put("{$this->folder}/custom.0.yaml", Yaml::dump([['uri' => 'api/custom']]));
        // An empty custom file is the shipped example with everything deleted.
        File::put("{$this->folder}/custom.1.yaml", '');
        File::put("{$this->folder}/notes.txt", 'not yaml');

        $this->assertSame(['Posts'], array_keys(Camel::loadEndpointsIntoGroups($this->folder)));
        $this->assertSame([['uri' => 'api/custom']], Camel::loadUserDefinedEndpoints($this->folder));
    }

    #[Test]
    public function aMissingFolderHoldsNoEndpoints(): void
    {
        $missing = "{$this->folder}/missing";

        $this->assertSame([], Camel::loadEndpointsIntoGroups($missing));
        $this->assertSame([], Camel::loadUserDefinedEndpoints($missing));
    }
}
