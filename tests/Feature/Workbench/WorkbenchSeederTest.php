<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Workbench;

use Ipsocode\Scribe\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

/**
 * A smoke test of the Workbench seeder, the one place every factory runs
 * together. Example models for API-resource responses come from these
 * factories, and a broken one otherwise surfaces as a confusing failure in
 * whichever test uses it first.
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
