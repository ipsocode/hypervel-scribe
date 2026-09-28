<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Commands;

use Hypervel\Console\Command;
use InvalidArgumentException;
use Ipsocode\Scribe\Tools\ConfigDiffer;

/**
 * Print how the application's Scribe config differs from the package's
 * defaults.
 *
 * Meant for bug reports: a published `config/scribe.php` is ~270 lines of
 * mostly-untouched defaults, and the handful of lines that were actually
 * changed are the only ones worth pasting into an issue.
 */
class DiffConfig extends Command
{
    /**
     * Paths where "differs from the default" is the normal state.
     *
     * Prose (`description`, `intro_text`, `auth.extra_info`) and the
     * per-application lists (`routes`, `groups`, `example_languages`) say
     * nothing about what might be misconfigured, and `routes` alone would
     * dominate the output.
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
     * Paths to compare as unordered lists rather than key by key.
     *
     * Both are lists whose *membership* is the configuration — a strategy added
     * to or removed from a stage, a model source reordered. Diffing them by
     * index reports every entry after an insertion as changed.
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
