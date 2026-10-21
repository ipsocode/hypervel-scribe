<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\Scribe\Extracting\Shared\ResponseFileTools;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What `@responseFile` hands back once the JSON written after the path is
 * merged into the file's own.
 */
class ResponseFileToolsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'scribe-response-');
    }

    protected function tearDown(): void
    {
        unlink($this->file);

        parent::tearDown();
    }

    private function merge(string $fileContents, array|string|null $merge): string
    {
        file_put_contents($this->file, $fileContents);

        return ResponseFileTools::getResponseContents($this->file, $merge);
    }

    #[UnitTest]
    #[Test]
    public function withNothingToMergeTheFileComesBackAsItIs(): void
    {
        $this->assertSame('{"a": {}}', $this->merge('{"a": {}}', null));
    }

    #[UnitTest]
    #[Test]
    public function anApostropheInsideAMergedValueSurvives(): void
    {
        $this->assertSame(
            '{"id":1,"name":"O\'Brien"}',
            $this->merge('{"id": 1}', '{"name": "O\'Brien"}'),
        );
    }

    #[UnitTest]
    #[Test]
    public function singleQuotedJsonIsStillAccepted(): void
    {
        $this->assertSame('{"id":1,"name":"Ada"}', $this->merge('{"id": 1}', "{'name': 'Ada'}"));
    }

    #[UnitTest]
    #[Test]
    public function aMergeGivenAsAnArrayIsMergedToo(): void
    {
        $this->assertSame('{"id":2}', $this->merge('{"id": 1}', ['id' => 2]));
    }

    #[UnitTest]
    #[Test]
    public function emptyObjectsAndSlashesInTheFileAreLeftAsTheyWere(): void
    {
        $this->assertSame(
            '{"data":{},"url":"http://x/y","name":"Ada"}',
            $this->merge('{"data": {}, "url": "http://x/y"}', '{"name": "Ada"}'),
        );
    }

    #[UnitTest]
    #[Test]
    public function aFileThatIsNotJsonThrowsAnErrorThatNamesTheTag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('@responseFile');

        $this->merge('not json', '{"name": "Ada"}');
    }

    #[UnitTest]
    #[Test]
    public function mergeJsonThatIsNotAnObjectThrowsAnErrorThatNamesTheTag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('@responseFile');

        $this->merge('{"id": 1}', '{"name": ');
    }
}
