<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

/*
 * A route outside the `api/*` prefix, which the matcher must not pick up.
 */

Route::get('/', fn () => 'Hypervel Scribe Workbench')->name('home');
