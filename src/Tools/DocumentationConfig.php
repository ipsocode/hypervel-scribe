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
     * Get a config item with dot notation, or $default if the key does not exist.
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
     * Reject a `type` nobody implements, rather than writing static docs at it.
     *
     * `outputIsStatic()` means "not routed through the app", so a typo would
     * otherwise come out of it as `true`: generation would report success while
     * writing an `index.html` into `public/docs` that no route serves.
     *
     * @throws InvalidArgumentException if `type` is not one of {@see self::TYPES}
     */
    public function assertTypeIsSupported(): void
    {
        $type = $this->get('type');

        if (in_array($type, self::TYPES, true)) {
            return;
        }

        throw new InvalidArgumentException(
            '`type` is set to ' . (is_string($type) ? "'{$type}'" : get_debug_type($type))
            . ', which is not an output type Scribe knows. '
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
