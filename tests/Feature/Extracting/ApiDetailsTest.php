<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Support\Facades\File;
use Ipsocode\Scribe\Extracting\ApiDetails;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\PathConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The prose layer of the docs: `intro.md` and `auth.md`, written into the
 * intermediate directory beside the endpoint YAML.
 *
 * These two files are the only part of the generated page a user is meant to
 * edit by hand, so the interesting behaviour is not the rendering but the
 * bookkeeping: `.filehashes` records what Scribe last wrote, and a file whose
 * content no longer matches its recorded hash is left alone on the next run.
 */
class ApiDetailsTest extends DatabaseTestCase
{
    private string $outputPath;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputPath = sys_get_temp_dir() . '/scribe-api-details-' . bin2hex(random_bytes(6));

        // Nothing bootstraps the console output outside `scribe:generate`, and
        // the "skipping modified file" warning is behaviour worth asserting on
        // rather than letting it open its own handle on STDOUT.
        $this->output = new BufferedOutput;
        c::bootstrapOutput($this->output);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputPath);

        parent::tearDown();
    }

    /**
     * The extractor under a consuming application's configuration, with overrides.
     *
     * The views read keys the package's own config always supplies — `auth`,
     * `intro_text`, `base_url` — so a bare array here would test them against a
     * configuration no application can actually have.
     */
    private function apiDetails(array $config = [], bool $preserveUserChanges = true): ApiDetails
    {
        return new ApiDetails(
            new PathConfig('scribe', $this->outputPath),
            new DocumentationConfig(array_replace_recursive(config('scribe'), $config)),
            $preserveUserChanges,
        );
    }

    private function write(array $config = [], bool $preserveUserChanges = true): void
    {
        $this->apiDetails($config, $preserveUserChanges)->writeMarkdownFiles();
    }

    private function contentsOf(string $file): string
    {
        return (string) file_get_contents("{$this->outputPath}/{$file}");
    }

    #[Test]
    public function writesTheMarkdownFilesIntoTheIntermediateDirectory(): void
    {
        // The directory belongs to the endpoint extraction, which has not
        // necessarily run first — creating it is part of the job.
        $this->assertDirectoryDoesNotExist($this->outputPath);

        $this->write();

        $this->assertFileExists("{$this->outputPath}/intro.md");
        $this->assertFileExists("{$this->outputPath}/auth.md");
    }

    #[Test]
    public function theIntroCarriesTheDescriptionTheBaseUrlAndTheIntroText(): void
    {
        $this->write([
            'description' => 'An API for testing.',
            'intro_text' => 'Welcome aboard.',
            'base_url' => 'https://api.example.com',
        ]);

        $intro = $this->contentsOf('intro.md');

        $this->assertStringContainsString('# Introduction', $intro);
        $this->assertStringContainsString('An API for testing.', $intro);
        $this->assertStringContainsString('<code>https://api.example.com</code>', $intro);
        $this->assertStringContainsString('Welcome aboard.', $intro);
        // Markdown for the theme's parser to render, not HTML.
        $this->assertStringNotContainsString('{{', $intro);
    }

    #[Test]
    public function theShippedIntroTextCarriesNoLeadingIndentation(): void
    {
        // `intro_text` is a heredoc in config/scribe.php, and PHP strips only as
        // much indentation as the closing marker carries. Leave the marker
        // shallower than the body and every line keeps four leading spaces,
        // which Markdown reads as a code block — so the default intro reaches
        // the page as escaped source rather than as the paragraph and <aside>
        // it is. `theShippedIntroTextRendersAsProse` is the same guard at the
        // other end of the pipeline.
        $indented = array_values(array_filter(
            explode("\n", (string) config('scribe.intro_text')),
            fn (string $line) => preg_match('/^ {4}\S/', $line) === 1,
        ));

        $this->assertSame([], $indented, 'Indented lines in `intro_text` render as a Markdown code block.');
    }

    #[Test]
    public function theAuthFileSaysSoWhenTheApiIsNotAuthenticated(): void
    {
        $this->write(['auth' => ['enabled' => false]]);

        $auth = $this->contentsOf('auth.md');

        $this->assertStringContainsString('# Authenticating requests', $auth);
        $this->assertStringContainsString('This API is not authenticated.', $auth);
        // The extra info is about how to get a token, so it has no place on an
        // API that takes none.
        $this->assertStringNotContainsString('Generate API token', $auth);
    }

    #[Test]
    #[DataProvider('authStrategies')]
    public function theAuthFileExplainsHowToAuthenticate(string $in, string $instruction): void
    {
        $this->write(['auth' => [
            'enabled' => true,
            'in' => $in,
            'name' => 'Api-Key',
            'placeholder' => '{TOKEN}',
            'extra_info' => 'Tokens live in your dashboard.',
        ]]);

        $auth = $this->contentsOf('auth.md');

        $this->assertStringContainsString($instruction, $auth);
        // Every strategy gets the same closing note, and then whatever the
        // application wants to add.
        $this->assertStringContainsString('All authenticated endpoints are marked', $auth);
        $this->assertStringContainsString('Tokens live in your dashboard.', $auth);
    }

    /**
     * One `auth.in` value per translated instruction, and what it should say.
     *
     * `name` and `placeholder` are interpolated into the string, so a strategy
     * that stopped substituting them would still contain the surrounding prose —
     * hence asserting on the filled-in sentence rather than a fragment of it.
     */
    public static function authStrategies(): array
    {
        return [
            'bearer' => ['bearer', 'an **`Authorization`** header with the value **`"Bearer {TOKEN}"`**'],
            'basic' => ['basic', 'an **`Authorization`** header in the form **`"Basic {credentials}"`**'],
            'header' => ['header', 'a **`Api-Key`** header with the value **`"{TOKEN}"`**'],
            'query' => ['query', 'a query parameter **`Api-Key`** in the request'],
            'body' => ['body', 'a parameter **`Api-Key`** in the body of the request'],
            'query_or_body' => ['query_or_body', 'a parameter **`Api-Key`** either in the query string'],
        ];
    }

    #[Test]
    public function theTrackingFileRecordsAHashOfEverythingItWrote(): void
    {
        $this->write();

        $tracked = $this->contentsOf('.filehashes');

        $this->assertStringContainsString("YOU SHOULDN'T MODIFY OR DELETE THIS FILE", $tracked);
        foreach (['intro.md', 'auth.md'] as $file) {
            $this->assertStringContainsString(
                "{$this->outputPath}/{$file}=" . hash_file('md5', "{$this->outputPath}/{$file}"),
                $tracked,
            );
        }
    }

    #[Test]
    public function aHandEditedFileSurvivesTheNextRun(): void
    {
        $this->write();
        file_put_contents("{$this->outputPath}/intro.md", '# Introduction

Written by a human.');

        $this->write();

        $this->assertStringContainsString('Written by a human.', $this->contentsOf('intro.md'));
        $this->assertStringContainsString(
            "Skipping modified file {$this->outputPath}/intro.md",
            $this->output->fetch(),
        );
        // Only the edited file is left alone; the other is still regenerated.
        $this->assertStringContainsString('# Authenticating requests', $this->contentsOf('auth.md'));
    }

    #[Test]
    public function aHandEditedFileStaysProtectedAcrossFurtherRuns(): void
    {
        // The skipped file's *recorded* hash has to survive too. Writing back
        // the current hash instead would adopt the user's edit as Scribe's own
        // output, and the next run would overwrite it.
        $this->write();
        file_put_contents("{$this->outputPath}/intro.md", 'Written by a human.');

        $this->write();
        $this->write();

        $this->assertSame('Written by a human.', $this->contentsOf('intro.md'));
    }

    #[Test]
    public function forcingDiscardsAHandEditedFile(): void
    {
        $this->write();
        file_put_contents("{$this->outputPath}/intro.md", 'Written by a human.');

        $this->write(preserveUserChanges: false);

        $this->assertStringNotContainsString('Written by a human.', $this->contentsOf('intro.md'));
        $this->assertStringContainsString('# Introduction', $this->contentsOf('intro.md'));
        $this->assertStringContainsString(
            "Discarding manual changes for file {$this->outputPath}/intro.md",
            $this->output->fetch(),
        );
    }

    #[Test]
    public function deletingTheTrackingFileForfeitsTheProtection(): void
    {
        // `.filehashes` is the only record of what Scribe wrote, so without it
        // an edited file is indistinguishable from a generated one — hence the
        // "DO NOT DELETE" banner it carries.
        $this->write();
        file_put_contents("{$this->outputPath}/intro.md", 'Written by a human.');
        unlink("{$this->outputPath}/.filehashes");

        $this->write();

        $this->assertStringNotContainsString('Written by a human.', $this->contentsOf('intro.md'));
    }

    #[Test]
    public function fallsBackToTheApplicationsOwnConfigurationWhenNoneIsInjected(): void
    {
        // The `paths` object names the config file, which is what makes
        // `--config=other_scribe` reach a second set of docs.
        (new ApiDetails(new PathConfig('scribe', $this->outputPath)))->writeMarkdownFiles();

        $this->assertStringContainsString(
            config('scribe.intro_text'),
            $this->contentsOf('intro.md'),
        );
    }

    #[Test]
    public function theBaseUrlFallsBackToTheApplicationUrl(): void
    {
        $this->write(['base_url' => null]);

        $this->assertStringContainsString(
            '<code>' . config('app.url') . '</code>',
            $this->contentsOf('intro.md'),
        );
    }

    #[Test]
    public function theAuthFileGetsTheSameProtectionAsTheIntro(): void
    {
        // Each file is written by its own method, carrying its own copy of the
        // preserve/discard branch — so covering one says nothing about the other.
        $this->write();
        file_put_contents("{$this->outputPath}/auth.md", 'Written by a human.');

        $this->write();

        $this->assertSame('Written by a human.', $this->contentsOf('auth.md'));
        $this->assertStringContainsString(
            "Skipping modified file {$this->outputPath}/auth.md",
            $this->output->fetch(),
        );

        $this->write(preserveUserChanges: false);

        $this->assertStringContainsString('# Authenticating requests', $this->contentsOf('auth.md'));
        $this->assertStringContainsString(
            "Discarding manual changes for file {$this->outputPath}/auth.md",
            $this->output->fetch(),
        );
    }

    #[Test]
    public function anUnchangedFileIsRewrittenWithoutComplaint(): void
    {
        $this->write();
        $first = $this->contentsOf('intro.md');

        $this->write();

        $this->assertSame($first, $this->contentsOf('intro.md'));
        $this->assertStringNotContainsString('Skipping modified file', $this->output->fetch());
    }
}
