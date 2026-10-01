<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Tools\DocumentationConfig;

abstract class Strategy
{
    public ?ExtractedEndpointData $endpointData;

    public function __construct(protected DocumentationConfig $config)
    {
    }

    /**
     * Extracts this strategy's data for one endpoint, given the settings it was
     * configured with. Null adds nothing.
     */
    abstract public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array;

    public function getConfig(): DocumentationConfig
    {
        return $this->config;
    }

    /**
     * Pairs the strategy with its settings, the tuple a strategies list in
     * config/scribe.php takes.
     *
     * @param array $only The routes to apply this strategy to, and no others.
     *                    Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @param array $except The routes not to apply this strategy to. A route matching both $only and $except is skipped.
     *                      Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @return array{string,array} tuple of strategy class FQN and specified settings
     */
    public static function wrapWithSettings(
        array $only = [],
        array $except = [],
        array $otherSettings = [],
    ): array {
        return [
            static::class,
            ['only' => $only, 'except' => $except, ...$otherSettings],
        ];
    }
}
