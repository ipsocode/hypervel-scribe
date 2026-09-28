<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

/*
 * Non-API routes.
 *
 * Scribe's default route rules only match the `api/*` prefix, so this file
 * exists to give the matcher something it must *not* pick up. Without a route
 * outside the prefix, "only matched routes are documented" is untested.
 */

Route::get('/', fn () => 'Hypervel Scribe Workbench')->name('home');
