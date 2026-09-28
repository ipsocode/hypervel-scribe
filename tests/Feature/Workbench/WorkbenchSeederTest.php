<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Workbench;

use Ipsocode\Scribe\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

/**
 * The Workbench application is what this package is tested against, so its
 * seeder is worth a smoke test of its own: it is the one place that exercises
 * every factory together, and a broken factory otherwise only shows up as a
 * confusing failure in whichever test happens to use it first.
 *
 * Scribe leans on those factories directly — `#[ResponseFromApiResource]` builds
 * its example models from them — so "the factories still work" is a claim this
 * package genuinely depends on.
 */
class WorkbenchSeederTest extends DatabaseTestCase
{
    /**
     * `RefreshDatabase` calls this inside the transaction it wraps each test
     * in, so the seeded rows are rolled back with everything else. Testbench's
     * defineDatabaseSeeders() runs before that transaction opens: its rows
     * would be committed to the database the next test reuses.
     */
    protected function afterRefreshingDatabase(): void
    {
        parent::afterRefreshingDatabase();

        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function theWorkbenchSeederPopulatesEveryFixtureModel(): void
    {
        $this->assertSame(1, User::query()->count());
        $this->assertSame(5, Post::query()->count());
        $this->assertSame(2, Post::query()->where('published', true)->count());
    }

    #[Test]
    public function seededPostsAreAttachedToTheSeededUser(): void
    {
        // The `author` relation is what a nested API resource would document.
        $this->assertSame(
            User::query()->value('id'),
            Post::query()->first()->author->id,
        );
    }
}
