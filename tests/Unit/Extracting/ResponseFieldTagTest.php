<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Camel\Extraction\ResponseCollection;
use Ipsocode\Scribe\Extracting\Strategies\ResponseFields\GetFromResponseFieldTag;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * `@responseField name type required description`: the type and "required"
 * are optional, and a tag with three words or fewer must not lose its
 * description or its required flag.
 */
class ResponseFieldTagTest extends TestCase
{
    /**
     * @return array<string, mixed> the field parsed from a tag with this content
     */
    private function parse(string $content): array
    {
        $strategy = new GetFromResponseFieldTag(new DocumentationConfig([]));
        // Built without its constructor, which needs a route this test has no use for.
        $endpointData = (new ReflectionClass(ExtractedEndpointData::class))->newInstanceWithoutConstructor();
        $endpointData->responses = new ResponseCollection([]);
        $strategy->endpointData = $endpointData;

        $tag = new class($content) {
            public function __construct(private string $content)
            {
            }

            public function getName(): string
            {
                return 'responseField';
            }

            public function getContent(): string
            {
                return $this->content;
            }
        };

        return array_values($strategy->getFromTags([$tag]))[0];
    }

    #[Test]
    #[UnitTest]
    public function aTypedFieldWithADescriptionKeepsBoth(): void
    {
        $field = $this->parse('user_id integer The ID of the user.');

        $this->assertSame('integer', $field['type']);
        $this->assertSame('The ID of the user.', $field['description']);
        $this->assertFalse($field['required']);
    }

    #[Test]
    #[UnitTest]
    public function aTypeWordThatIsNotATypeBelongsToTheDescription(): void
    {
        $field = $this->parse('id The ID.');

        $this->assertSame('', $field['type']);
        $this->assertSame('The ID.', $field['description']);
    }

    #[Test]
    #[UnitTest]
    public function aRequiredFlagWithNoDescriptionIsStillRequired(): void
    {
        $field = $this->parse('id integer required');

        $this->assertSame('integer', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertSame('', $field['description']);
    }

    #[Test]
    #[UnitTest]
    public function aRequiredFieldWithADescriptionKeepsAllThree(): void
    {
        $field = $this->parse('text string required The text.');

        $this->assertTrue($field['required']);
        $this->assertSame('The text.', $field['description']);
    }
}
