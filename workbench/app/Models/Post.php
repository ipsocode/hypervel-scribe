<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;

/**
 * A blog post, written by a Workbench\App\Models\User.
 *
 * Scribe instantiates example models to render API resource responses, so this
 * model exists to be *found* — through its factory, and failing that through a
 * database row. Both paths are exercised by the suite, which is why the table
 * and the factory both have to be real.
 */
class Post extends Model
{
    use HasFactory;

    protected ?string $table = 'posts';

    protected array $fillable = [
        'user_id',
        'title',
        'body',
        'published',
    ];

    protected array $casts = [
        'published' => 'bool',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('added_by');
    }
}
