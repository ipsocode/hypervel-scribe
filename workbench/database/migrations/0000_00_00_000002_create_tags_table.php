<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
            // Carried by the factory's `pivotTags()` method, which is how
            // Scribe passes pivot attributes into `hasAttached()`.
            $table->string('added_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::drop('post_tag');
        Schema::drop('tags');
    }
};
