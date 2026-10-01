<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Camel;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Camel\Extraction\Metadata;
use Ipsocode\Camel\Extraction\Parameter;
use Ipsocode\Camel\Extraction\ResponseCollection;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Camel\Output\Parameter as OutputParameter;
use Ipsocode\Scribe\Tests\CreatesMockEndpoints;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * BaseDTO implements construction, casting and array access itself rather than
 * through a DTO library, so that behaviour is exercised directly here.
 */
class BaseDTOTest extends TestCase
{
    use CreatesMockEndpoints;

    #[UnitTest]
    #[Test]
    public function assignsKnownPropertiesAndKeepsDefaultsForTheRest(): void
    {
        $param = new Parameter(['name' => 'page', 'type' => 'integer', 'required' => true]);

        $this->assertSame('page', $param->name);
        $this->assertSame('integer', $param->type);
        $this->assertTrue($param->required);
        // Untouched properties keep their declared defaults.
        $this->assertNull($param->description);
        $this->assertFalse($param->nullable);
        $this->assertSame([], $param->enumValues);
    }

    #[UnitTest]
    #[Test]
    public function ignoresUnknownProperties(): void
    {
        $param = new Parameter(['name' => 'page', 'not_a_real_property' => 'x']);

        $this->assertSame('page', $param->name);
        $this->assertFalse(property_exists($param, 'not_a_real_property'));
    }

    #[UnitTest]
    #[Test]
    public function createMergesDataOverInheritedValues(): void
    {
        $param = Parameter::create(
            ['type' => 'integer'],
            ['name' => 'page', 'type' => 'string', 'required' => true],
        );

        $this->assertSame('page', $param->name);
        $this->assertSame('integer', $param->type);
        $this->assertTrue($param->required);
    }

    #[UnitTest]
    #[Test]
    public function createAndMakeReturnExistingInstancesUnchanged(): void
    {
        $param = new Parameter(['name' => 'page']);

        $this->assertSame($param, Parameter::create($param));
        $this->assertSame($param, Parameter::make($param));
    }

    #[UnitTest]
    #[Test]
    public function arrayOfMapsAListOfArraysIntoInstances(): void
    {
        $params = Parameter::arrayOf([['name' => 'a'], ['name' => 'b']]);

        $this->assertCount(2, $params);
        $this->assertContainsOnlyInstancesOf(Parameter::class, $params);
        $this->assertSame('a', $params[0]->name);
        $this->assertSame('b', $params[1]->name);
    }

    #[UnitTest]
    #[Test]
    public function supportsArrayAccess(): void
    {
        $param = new Parameter(['name' => 'page']);

        $this->assertTrue(isset($param['name']));
        $this->assertSame('page', $param['name']);

        $param['type'] = 'integer';
        $this->assertSame('integer', $param->type);
    }

    #[UnitTest]
    #[Test]
    public function exceptOmitsNamedProperties(): void
    {
        $param = new OutputParameter(['name' => 'page', 'type' => 'integer']);

        $array = $param->toArray();

        $this->assertArrayHasKey('name', $array);
        // OutputParameter::toArray() is defined as except('__fields').
        $this->assertArrayNotHasKey('__fields', $array);
    }

    #[UnitTest]
    #[Test]
    public function castsNestedDtoAndCollectionProperties(): void
    {
        $endpoint = $this->createMockEndpointData();

        // Plain arrays passed in are hydrated into the declared DTO types.
        $this->assertInstanceOf(Metadata::class, $endpoint->metadata);
        $this->assertInstanceOf(ResponseCollection::class, $endpoint->responses);
        $this->assertContainsOnlyInstancesOf(Parameter::class, $endpoint->urlParameters);
    }

    #[UnitTest]
    #[Test]
    public function toArrayRecursivelyUnwrapsNestedDtos(): void
    {
        $endpoint = OutputEndpointData::create($this->createMockEndpointData([
            'metadata.title' => 'List users',
        ]));

        $array = $endpoint->toArray();

        $this->assertIsArray($array['metadata']);
        $this->assertSame('List users', $array['metadata']['title']);
    }
}
