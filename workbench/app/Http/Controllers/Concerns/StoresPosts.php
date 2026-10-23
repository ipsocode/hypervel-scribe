<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Concerns;

use Hypervel\Http\Request;

trait StoresPosts
{
    /**
     * Store a post.
     */
    public function store(Request $request): array
    {
        $request->validate(['title' => 'required|string']);

        return [];
    }
}
