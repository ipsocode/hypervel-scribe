<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Hypervel\Support\Str;
use InvalidArgumentException;

class DocumentationConfig
{
    public array $data;

    public function __construct(array $config = [])
    {
        $this->data = $config;
    }

    /**
     * Get a config item with dot notation.
     * If the key does not exist, $default (or null) will be returned.
     *
     * @param mixed $default
     * @return array|mixed
     */
    public function get(string $key, $default = null)
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * The output types `scribe.type` accepts.
     *
     * The three predicates below split this list two ways rather than matching
     * it, so a value that is not on it does not fall out of them — it falls
     * into "static, served from the filesystem". {@see \Ipsocode\Scribe\Writing\Writer}
     * checks membership before that becomes what gets written.
     *
     * @var string[]
     */
    public const array TYPES = ['static', 'hypervel', 'external_static', 'external_hypervel'];

    /**
     * The upstream type names this port renames, and what they became.
     *
     * A config file copied from knuckleswtf/scribe carries the Laravel names;
     * they are the likeliest wrong value by far, so they get told what happened
     * rather than just being rejected.
     *
     * @var array<string,string>
     */
    public const array RENAMED_TYPES = ['laravel' => 'hypervel', 'external_laravel' => 'external_hypervel'];

    /**
     * Reject a `type` nobody implements, rather than writing static docs at it.
     *
     * `outputIsStatic()` means "not routed through the app", so every value that
     * is not `hypervel` or `external_hypervel` — a typo, or upstream's own
     * `laravel`, which is what a config file copied from knuckleswtf/scribe
     * carries — comes out of it as `true`. Generation would then report success
     * while writing an `index.html` into `public/docs` that nobody asked for, no
     * route serves, and nobody goes looking for. Naming the mistake is the only
     * useful thing to do with it.
     *
     * @throws InvalidArgumentException if `type` is not one of {@see self::TYPES}
     */
    public function assertTypeIsSupported(): void
    {
        $type = $this->get('type');

        if (in_array($type, self::TYPES, true)) {
            return;
        }

        $renamedTo = is_string($type) ? (self::RENAMED_TYPES[$type] ?? null) : null;

        throw new InvalidArgumentException(
            '`type` is set to ' . (is_string($type) ? "'{$type}'" : get_debug_type($type))
            . ', which is not an output type Scribe knows. '
            . ($renamedTo ? "This port renames upstream's '{$type}' to '{$renamedTo}'. " : '')
            . 'Set it to one of: ' . implode(', ', self::TYPES) . '.'
        );
    }

    public function outputIsStatic(): bool
    {
        return ! $this->outputRoutedThroughApp();
    }

    public function outputRoutedThroughApp(): bool
    {
        return Str::is(['hypervel', 'external_hypervel'], $this->get('type'));
    }

    public function outputIsExternal(): bool
    {
        return Str::is(['external_static', 'external_hypervel'], $this->get('type'));
    }
}
