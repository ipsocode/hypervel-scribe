<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            // Nullable because PostFactory sets no author; Scribe attaches one
            // through the `author` relation only when an endpoint asks for it.
            $table->foreignId('user_id')->nullable();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('posts');
    }
};
