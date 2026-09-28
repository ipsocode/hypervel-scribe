<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\InheritedMetadataController;
use Workbench\App\Http\Controllers\MetadataController;

/**
 * An endpoint's title, description, group, subgroup, deprecation and auth
 * status all come out of docblocks — and each can be written on the method or
 * on the controller, with the method winning. The two are separate code paths,
 * and a group tag additionally has to guess whether the prose under it is the
 * group's description or the endpoint's title.
 */
class MetadataTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/meta/group-no-title', [MetadataController::class, 'groupWithNoTitle'])
            ->name('meta.groupNoTitle');
        $router->get('api/meta/group-with-title', [MetadataController::class, 'groupWithTitle'])
            ->name('meta.groupWithTitle');
        $router->get('api/meta/unauthenticated', [MetadataController::class, 'unauthenticated'])
            ->name('meta.unauthenticated');
        $router->get('api/meta/deprecated-bare', [MetadataController::class, 'deprecatedBare'])
            ->name('meta.deprecatedBare');
        $router->get('api/meta/deprecated-reason', [MetadataController::class, 'deprecatedWithReason'])
            ->name('meta.deprecatedReason');
        $router->get('api/meta/subgroup', [MetadataController::class, 'subgrouped'])
            ->name('meta.subgroup');
        $router->get('api/meta/inherited-subgroup', [InheritedMetadataController::class, 'inheritsTheSubgroup'])
            ->name('meta.inheritedSubgroup');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function aGroupTagOnTheMethodOverridesTheControllersGroup(): void
    {
        $this->assertSame('Method group', $this->extract('meta.groupWithTitle')->metadata->groupName);
    }

    #[Test]
    public function proseUnderAGroupTagIsTheGroupsDescriptionWhenTheEndpointHasATitle(): void
    {
        $metadata = $this->extract('meta.groupWithTitle')->metadata;

        $this->assertSame('Fetch the things.', $metadata->title);
        $this->assertSame("The group's description, on the lines after its name.", $metadata->groupDescription);
    }

    #[Test]
    public function proseUnderAGroupTagIsTheEndpointsTitleWhenThereIsNoOther(): void
    {
        // Written without a summary line, the text under `@group` reads as the
        // endpoint's title — the group already has a name.
        $metadata = $this->extract('meta.groupNoTitle')->metadata;

        $this->assertSame("The group's description, on the lines after its name.", $metadata->title);
        $this->assertSame('', $metadata->groupDescription);
    }

    #[Test]
    public function anUnauthenticatedTagMarksAnEndpointPublic(): void
    {
        $this->assertFalse($this->extract('meta.unauthenticated')->metadata->authenticated);
    }

    #[Test]
    public function aBareDeprecatedTagJustMarksTheEndpoint(): void
    {
        $this->assertTrue($this->extract('meta.deprecatedBare')->metadata->deprecated);
    }

    #[Test]
    public function aDeprecatedTagWithProseCarriesItThrough(): void
    {
        // The coding standard normalises the tag's wording, so the assertion
        // is on what the tag carried rather than on its exact punctuation.
        $deprecated = $this->extract('meta.deprecatedReason')->metadata->deprecated;

        $this->assertIsString($deprecated);
        $this->assertStringContainsString('groupWithTitle', $deprecated);
    }

    #[Test]
    public function aSubgroupOnTheMethodIsUsedWithItsDescription(): void
    {
        $metadata = $this->extract('meta.subgroup')->metadata;

        $this->assertSame('Method subgroup', $metadata->subgroup);
        $this->assertSame('Written on the method.', $metadata->subgroupDescription);
    }

    #[Test]
    public function aSubgroupOnTheControllerIsInheritedByItsMethods(): void
    {
        $metadata = $this->extract('meta.inheritedSubgroup')->metadata;

        $this->assertSame('Class subgroup', $metadata->subgroup);
        $this->assertSame('Written on the controller.', $metadata->subgroupDescription);
    }
}
