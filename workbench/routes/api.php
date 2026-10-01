<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Workbench\App\Http\Controllers\CommentController;
use Workbench\App\Http\Controllers\FacadeValidationController;
use Workbench\App\Http\Controllers\HealthController;
use Workbench\App\Http\Controllers\InlineValidationController;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Http\Controllers\TagVariantsController;
use Workbench\App\Http\Controllers\TransformerController;
use Workbench\App\Http\Controllers\UserController;

/*
 * The API routes Scribe documents.
 *
 * Workbench loads this file with the `api` middleware group but no path prefix,
 * so `api/` is written out here for config/scribe.php's default `api/*` prefix
 * rule to match.
 */

Route::get('api/users', [UserController::class, 'index'])->name('users.index');
Route::get('api/users/{id}', [UserController::class, 'show'])->name('users.show');
Route::get('api/user', [UserController::class, 'me'])->name('users.me');

Route::get('api/posts', [PostController::class, 'index'])->name('posts.index');
Route::post('api/posts', [PostController::class, 'store'])->name('posts.store');
Route::get('api/posts/{id}', [PostController::class, 'show'])->name('posts.show');
Route::delete('api/posts/{id}', [PostController::class, 'destroy'])->name('posts.destroy');

Route::get('api/comments', [CommentController::class, 'index'])->name('comments.index');
Route::get('api/comments/{id}', [CommentController::class, 'show'])->name('comments.show');
Route::post('api/comments', [CommentController::class, 'store'])->name('comments.store');
Route::post('api/comments/{id}/report', [CommentController::class, 'report'])->name('comments.report');
Route::post('api/comments/{id}/pin', [CommentController::class, 'pin'])->name('comments.pin');
Route::delete('api/comments/{id}/pin', [CommentController::class, 'unpin'])->name('comments.unpin');
Route::get('api/comments/{id}/export', [CommentController::class, 'export'])->name('comments.export');

Route::get('api/posts-paginated', [PostController::class, 'paginated'])->name('posts.paginated');
Route::get('api/posts-simple', [PostController::class, 'simplePaginated'])->name('posts.simple');
Route::get('api/posts-cursor', [PostController::class, 'cursorPaginated'])->name('posts.cursor');
Route::get('api/posts-collection', [PostController::class, 'collectionResource'])->name('posts.collection');

Route::get('api/transformed/{id}', [TransformerController::class, 'show'])->name('transformed.show');
Route::get('api/transformed', [TransformerController::class, 'index'])->name('transformed.index');
Route::get('api/transformed-paginated', [TransformerController::class, 'paginated'])->name('transformed.paginated');
Route::get('api/transformed-attributed', [TransformerController::class, 'attributed'])->name('transformed.attributed');

Route::post('api/inline/variable', [InlineValidationController::class, 'fromVariable'])->name('inline.variable');
Route::post('api/inline/array', [InlineValidationController::class, 'arrayRules'])->name('inline.array');
Route::post('api/inline/facade', [FacadeValidationController::class, 'store'])->name('inline.facade');
Route::post('api/inline/controller', [InlineValidationController::class, 'viaController'])->name('inline.controller');
Route::post('api/inline/unreadable', [InlineValidationController::class, 'unreadableRules'])->name('inline.unreadable');
Route::post('api/inline/none', [InlineValidationController::class, 'noValidation'])->name('inline.none');

Route::get('api/tags/bare', [TagVariantsController::class, 'bare'])->name('tags.bare');
Route::get('api/tags/described', [TagVariantsController::class, 'described'])->name('tags.described');
Route::get('api/tags/typed', [TagVariantsController::class, 'typedOnly'])->name('tags.typed');
Route::get('api/tags/markers', [TagVariantsController::class, 'markers'])->name('tags.markers');
Route::get('api/tags/not-a-type', [TagVariantsController::class, 'notAType'])->name('tags.notAType');
Route::get('api/tags/no-example', [TagVariantsController::class, 'noExample'])->name('tags.noExample');
Route::get('api/tags/url/{bare}/{marked}/{typed}/{page}', [TagVariantsController::class, 'urlParamVariants'])->name('tags.urlParams');
Route::post('api/tags/body', [TagVariantsController::class, 'bodyParamVariants'])->name('tags.bodyParams');
Route::get('api/tags/fields', [TagVariantsController::class, 'inferredResponseFields'])->name('tags.fields');
Route::get('api/tags/ghost-field', [TagVariantsController::class, 'unknownResponseField'])->name('tags.ghostField');
Route::get('api/tags/list-fields', [TagVariantsController::class, 'listResponseFields'])->name('tags.listFields');

// Resource-style routes — a `plural/{singular}` URI under a `<resource>.<action>`
// name — are what UrlParamsNormalizer rewrites into `{id}`, and only routes
// spelled this way reach it. The nested one exercises the parent's `{x_id}`.
Route::get('api/blogs/{blog}', [PostController::class, 'showBound'])->name('blogs.show');
Route::get('api/authors/{author}/blogs/{blog}', [PostController::class, 'showBound'])->name('authors.blogs.show');

// Matches the `api/*` prefix rule but is named in the suite's `exclude` list,
// so it is the endpoint that proves exclusion actually excludes.
Route::get('api/health', [HealthController::class, 'show'])->name('health');
