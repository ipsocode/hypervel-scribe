<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Camel;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Camel\Extraction\Response;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A documented response, built from a response call or read back from the
 * `.scribe` YAML, which an earlier version may have written.
 */
class ResponseTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function leavesOutTheHeadersThatVaryOrCarrySessionsWhateverTheirCase(): void
    {
        $response = new Response([
            'status' => 200,
            'content' => '{}',
            'headers' => [
                'Set-Cookie' => 'session=real-session-id',
                'set-cookie' => 'XSRF-TOKEN=real-token',
                'SET-COOKIE' => 'remember=real',
                'Date' => 'Wed, 21 Oct 2026 07:00:00 GMT',
                'etag' => '"abc"',
                'Content-Type' => 'application/json',
                'X-Request-Id' => 'abc',
            ],
        ]);

        $this->assertSame([
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'abc',
        ], $response->headers);
    }
}
