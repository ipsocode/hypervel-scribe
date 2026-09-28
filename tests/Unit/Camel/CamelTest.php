<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Camel;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Camel\Camel;
use Ipsocode\Scribe\Tests\CreatesMockEndpoints;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CamelTest extends TestCase
{
    use CreatesMockEndpoints;

    /**
     * @param string[] $names
     */
    private function makeGroups(array $names): array
    {
        $groups = [];
        foreach ($names as $name) {
            $groups[$name] = ['name' => $name, 'description' => "{$name} desc", 'endpoints' => []];
        }

        return $groups;
    }

    #[UnitTest]
    #[Test]
    public function sortsGroupsWithNaturalOrderWhenNoConfigOrderIsGiven(): void
    {
        // SORT_NATURAL keeps numeric suffixes in human order (Group10 after Group2).
        $sorted = Camel::sortByConfigFileOrder($this->makeGroups(['Group10', 'Group2', 'Group1']), []);

        $this->assertSame(['Group1', 'Group2', 'Group10'], array_keys($sorted));
    }

    #[UnitTest]
    #[Test]
    public function sortsGroupsByExplicitConfigOrder(): void
    {
        $sorted = Camel::sortByConfigFileOrder(
            $this->makeGroups(['Zebra', 'Apple', 'Mango']),
            ['Mango', 'Apple'],
        );

        // Listed groups come first in config order; unlisted ones are appended naturally.
        $this->assertSame(['Mango', 'Apple', 'Zebra'], collect($sorted)->pluck('name')->all());
    }

    #[UnitTest]
    #[Test]
    public function honoursAWildcardToPromoteAndDemoteGroups(): void
    {
        $sorted = Camel::sortByConfigFileOrder(
            $this->makeGroups(['Last', 'Beta', 'First', 'Alpha']),
            ['First', '*', 'Last'],
        );

        // 'First' is promoted, 'Last' is demoted, and the wildcard groups sort naturally in between.
        $this->assertSame(['First', 'Alpha', 'Beta', 'Last'], collect($sorted)->pluck('name')->all());
    }

    #[UnitTest]
    #[Test]
    public function preservesGroupDescriptionsThroughSorting(): void
    {
        $sorted = Camel::sortByConfigFileOrder($this->makeGroups(['Users']), ['Users']);

        $this->assertSame('Users desc', $sorted[0]['description']);
    }

    #[UnitTest]
    #[Test]
    public function detectsWhetherAGroupContainsAnEndpoint(): void
    {
        $endpoint = $this->createMockEndpointData(['uri' => 'api/users', 'httpMethods' => ['GET']]);
        $other = $this->createMockEndpointData(['uri' => 'api/posts', 'httpMethods' => ['GET']]);

        $group = ['endpoints' => [$endpoint]];

        $this->assertTrue(Camel::doesGroupContainEndpoint($group, $endpoint));
        $this->assertFalse(Camel::doesGroupContainEndpoint($group, $other));
    }
}
