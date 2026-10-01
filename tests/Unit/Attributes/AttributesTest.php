<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Attributes;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\Scribe\Attributes\Authenticated;
use Ipsocode\Scribe\Attributes\BodyParam;
use Ipsocode\Scribe\Attributes\Deprecated;
use Ipsocode\Scribe\Attributes\Endpoint;
use Ipsocode\Scribe\Attributes\GenericParam;
use Ipsocode\Scribe\Attributes\Group;
use Ipsocode\Scribe\Attributes\Header;
use Ipsocode\Scribe\Attributes\QueryParam;
use Ipsocode\Scribe\Attributes\Response;
use Ipsocode\Scribe\Attributes\ResponseField;
use Ipsocode\Scribe\Attributes\Subgroup;
use Ipsocode\Scribe\Attributes\Unauthenticated;
use Ipsocode\Scribe\Attributes\UrlParam;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Enums\PostStatus;

/**
 * The attribute classes are plain value objects, but their `toArray()` output is
 * what the attribute strategies feed straight into the extracted endpoint data —
 * so the shape of that array is the contract, not an implementation detail.
 */
class AttributesTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function genericParamExposesItsValuesAsAnArray(): void
    {
        $param = new GenericParam(
            name: 'page',
            type: 'integer',
            description: 'The page.',
            required: false,
            example: 2,
            enum: [1, 2, 3],
            nullable: true,
            deprecated: true,
        );

        $this->assertSame([
            'name' => 'page',
            'description' => 'The page.',
            'type' => 'integer',
            'required' => false,
            'example' => 2,
            'enumValues' => [1, 2, 3],
            'nullable' => true,
            'deprecated' => true,
        ], $param->toArray());
    }

    #[UnitTest]
    #[Test]
    public function genericParamDefaultsToARequiredStringWithNoEnum(): void
    {
        $param = (new GenericParam('name'))->toArray();

        $this->assertSame('string', $param['type']);
        $this->assertTrue($param['required']);
        $this->assertSame([], $param['enumValues']);
    }

    #[UnitTest]
    #[Test]
    public function genericParamUnwrapsAPhpBackedEnum(): void
    {
        $param = (new GenericParam('status', enum: PostStatus::class))->toArray();

        $this->assertSame(['draft', 'published'], $param['enumValues']);
    }

    #[UnitTest]
    #[Test]
    public function genericParamRejectsAnEnumThatIsNeitherAListNorAPhpEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GenericParam('status', enum: 'NotAnEnum'))->toArray();
    }

    #[UnitTest]
    #[Test]
    public function theParamAttributesAreAllGenericParams(): void
    {
        // They differ only in where PHP allows them and how the strategies read
        // them; the payload shape has to stay identical or the strategies would
        // each need their own reader.
        foreach ([QueryParam::class, BodyParam::class, UrlParam::class] as $class) {
            $this->assertInstanceOf(GenericParam::class, new $class('x'));
        }
    }

    #[UnitTest]
    #[Test]
    public function responseFieldDoesNotDefaultItsType(): void
    {
        // Unlike the other params: response field types are inferred from the
        // response body by the normalizer, and defaulting to string here would
        // pre-empt that.
        $this->assertNull((new ResponseField('id'))->toArray()['type']);
    }

    #[UnitTest]
    #[Test]
    public function endpointAndGroupCarryTheirTitleAndDescription(): void
    {
        $endpoint = new Endpoint('List users', 'Returns every user.', authenticated: true);
        $this->assertSame('List users', $endpoint->title);
        $this->assertSame('Returns every user.', $endpoint->description);
        $this->assertTrue($endpoint->authenticated);

        $group = new Group('Users', 'User endpoints.');
        $this->assertSame([
            'groupName' => 'Users',
            'groupDescription' => 'User endpoints.',
        ], $group->toArray());
    }

    #[UnitTest]
    #[Test]
    public function groupUnwrapsAPhpBackedEnumName(): void
    {
        $this->assertSame('draft', (new Group(PostStatus::Draft))->toArray()['groupName']);
    }

    #[UnitTest]
    #[Test]
    public function groupRejectsANameThatIsNeitherAStringNorABackedEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Group(['Users']))->toArray();
    }

    #[UnitTest]
    #[Test]
    public function subgroupCarriesItsNameAndDescription(): void
    {
        $this->assertSame([
            'subgroup' => 'Profile',
            'subgroupDescription' => 'Profile endpoints.',
        ], (new Subgroup('Profile', 'Profile endpoints.'))->toArray());
    }

    #[UnitTest]
    #[Test]
    public function subgroupUnwrapsAPhpBackedEnumName(): void
    {
        $this->assertSame('published', (new Subgroup(PostStatus::Published))->toArray()['subgroup']);
    }

    #[UnitTest]
    #[Test]
    public function subgroupRejectsANameThatIsNeitherAStringNorABackedEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Subgroup(42))->toArray();
    }

    #[UnitTest]
    #[Test]
    public function headerCarriesItsNameAndExample(): void
    {
        $this->assertSame(
            ['name' => 'Api-Version', 'example' => 'v1'],
            (new Header('Api-Version', 'v1'))->toArray(),
        );
    }

    #[UnitTest]
    #[Test]
    public function responseJsonEncodesAnArrayBody(): void
    {
        $response = (new Response(['message' => 'ok'], 201, 'Created'))->toArray();

        $this->assertSame(201, $response['status']);
        $this->assertSame('{"message":"ok"}', $response['content']);
        $this->assertSame('Created', $response['description']);
    }

    #[UnitTest]
    #[Test]
    public function responsePassesAStringBodyThroughUntouched(): void
    {
        $this->assertSame('{"raw": true}', (new Response('{"raw": true}'))->toArray()['content']);
    }

    #[UnitTest]
    #[Test]
    public function responseKeepsANullBodyNull(): void
    {
        // A 204 has no body, and json_encode(null) would write the string "null".
        $this->assertNull((new Response(status: 204))->toArray()['content']);
    }

    #[UnitTest]
    #[Test]
    public function authenticatedAndUnauthenticatedAreOpposites(): void
    {
        $this->assertSame(['authenticated' => true], (new Authenticated)->toArray());
        $this->assertSame(['authenticated' => false], (new Authenticated(false))->toArray());
        $this->assertSame(['authenticated' => false], (new Unauthenticated)->toArray());
    }

    #[UnitTest]
    #[Test]
    public function endpointAndGroupOnlyCarryAnAuthenticatedFlagWhenGivenOne(): void
    {
        // The key is omitted rather than emitted as null, so an attribute that
        // says nothing about auth leaves the group's default alone.
        $this->assertArrayNotHasKey('authenticated', (new Endpoint('Show a comment'))->toArray());
        $this->assertArrayNotHasKey('authenticated', (new Group('Comments'))->toArray());

        $this->assertFalse((new Endpoint('Show a comment', authenticated: false))->toArray()['authenticated']);
        $this->assertTrue((new Group('Comments', authenticated: true))->toArray()['authenticated']);
    }

    #[UnitTest]
    #[Test]
    public function deprecatedDefaultsToTrueAndAcceptsAReason(): void
    {
        $this->assertSame(['deprecated' => true], (new Deprecated)->toArray());
        $this->assertSame(['deprecated' => 'Use /v2 instead.'], (new Deprecated('Use /v2 instead.'))->toArray());
    }
}
