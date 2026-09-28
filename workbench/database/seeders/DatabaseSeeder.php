<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Hypervel\Database\Console\Seeds\WithoutModelEvents;
use Hypervel\Database\Seeder;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the Workbench application.
     *
     * The automated suite builds whatever data it needs per test; this exists
     * for driving the Workbench app by hand through the Testbench CLI
     * (`testbench db:seed`, then `testbench scribe:generate`) against an API
     * that returns something other than empty collections.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Post::factory()
            ->count(3)
            ->create(['user_id' => $user->id]);

        Post::factory()
            ->count(2)
            ->published()
            ->create(['user_id' => $user->id]);
    }
}
