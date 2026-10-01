<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label on a post.
 *
 * The many-to-many is the point: `@apiResourceModel ... with=tags` builds the
 * example model through `hasAttached()`, which is a different factory call from
 * every other relation type and carries the pivot attributes with it.
 */
class Tag extends Model
{
    use HasFactory;

    protected ?string $table = 'tags';

    protected array $fillable = ['name'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
