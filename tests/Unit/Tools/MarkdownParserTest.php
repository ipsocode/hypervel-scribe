<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\MarkdownParser;
use PHPUnit\Framework\Attributes\Test;

/**
 * Parsedown with an outline. The HTML theme builds its sidebar from the headings
 * this records, and links to them by the ids it stamps on, so the two have to
 * agree — a heading in the outline whose id never made it into the document is a
 * dead sidebar link.
 */
class MarkdownParserTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function stampsAnIdOnEveryHeadingItRenders(): void
    {
        $html = (new MarkdownParser)->text("# Getting started\n\n## Authentication");

        $this->assertStringContainsString('<h1 id="getting-started">Getting started</h1>', $html);
        $this->assertStringContainsString('<h2 id="authentication">Authentication</h2>', $html);
    }

    #[UnitTest]
    #[Test]
    public function recordsEachHeadingWithItsLevelAndSlug(): void
    {
        $parser = new MarkdownParser;
        $parser->text("# Introduction\n\nSome prose.\n\n## Base URL\n\n### Staging");

        $this->assertSame([
            ['text' => 'Introduction', 'level' => 1, 'slug' => 'introduction'],
            ['text' => 'Base URL', 'level' => 2, 'slug' => 'base-url'],
            ['text' => 'Staging', 'level' => 3, 'slug' => 'staging'],
        ], $parser->headings);
    }

    #[UnitTest]
    #[Test]
    public function slugifiesHeadingsThatAreNotAlreadyUrlSafe(): void
    {
        $parser = new MarkdownParser;
        $parser->text('# Errors & "gotchas"');

        $this->assertSame('errors-gotchas', $parser->headings[0]['slug']);
    }

    #[UnitTest]
    #[Test]
    public function accumulatesHeadingsAcrossDocumentsUntilTheyAreReset(): void
    {
        // The writer parses intro.md and auth.md into one section of the
        // sidebar, then resets before append.md so those headings sort after
        // the endpoints instead.
        $parser = new MarkdownParser;
        $parser->text('# Introduction');
        $parser->text('# Authenticating requests');

        $this->assertSame(['introduction', 'authenticating-requests'], array_column($parser->headings, 'slug'));

        $parser->headings = [];
        $parser->text('# Further reading');

        $this->assertSame(['further-reading'], array_column($parser->headings, 'slug'));
    }

    #[UnitTest]
    #[Test]
    public function leavesDocumentsWithoutHeadingsAlone(): void
    {
        $parser = new MarkdownParser;
        $html = $parser->text('Just a paragraph.');

        $this->assertSame('<p>Just a paragraph.</p>', $html);
        $this->assertSame([], $parser->headings);
    }
}
