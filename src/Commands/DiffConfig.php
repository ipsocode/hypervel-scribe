<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Commands;

use Hypervel\Console\Command;
use InvalidArgumentException;
use Ipsocode\Scribe\Tools\ConfigDiffer;

/**
 * Prints how the application's Scribe config differs from the package defaults,
 * so a bug report carries only the changed lines.
 */
class DiffConfig extends Command
{
    /**
     * Paths where differing from the default is normal: prose and per-application
     * lists say nothing about misconfiguration, and `routes` would dominate the output.
     */
    protected const array IGNORE_PATHS = [
        'example_languages',
        'routes',
        'description',
        'auth.extra_info',
        'intro_text',
        'groups',
    ];

    /**
     * Paths compared as unordered lists, because their membership is the
     * configuration; diffing by index would report every entry after an
     * insertion as changed.
     */
    protected const array AS_LIST = [
        'strategies.*',
        'examples.models_source',
    ];

    protected ?string $signature = 'scribe:config-diff
                            {--config=scribe : Choose which config file to use}
    ';

    protected string $description = 'Dump your changed config to the console. Use this when posting bug reports.';

    public function handle(): int
    {
        $configName = (string) $this->option('config');
        $usersConfig = config($configName);

        if (! is_array($usersConfig)) {
            throw new InvalidArgumentException("The specified config (config/{$configName}.php) doesn't exist.");
        }

        $defaultConfig = require __DIR__ . '/../../config/scribe.php';

        $differ = new ConfigDiffer(
            original: $defaultConfig,
            changed: $usersConfig,
            ignorePaths: self::IGNORE_PATHS,
            asList: self::AS_LIST,
        );

        $diff = $differ->getDiff();

        if (empty($diff)) {
            $this->info('------ SAME AS DEFAULT CONFIG ------');

            return self::SUCCESS;
        }

        foreach ($diff as $key => $item) {
            $this->line("{$key} => {$item}");
        }

        return self::SUCCESS;
    }
}
