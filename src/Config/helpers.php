<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Config;

use Hypervel\Support\Arr;

// A strategy entry is either a class name, e.g. Strategies\Responses\ResponseCalls::class,
// or a tuple of the class name (or "static_data") and its settings array.

/**
 * Remove one or more strategies from a list of strategies.
 */
function removeStrategies(array $strategiesList, array $strategyNamesToRemove): array
{
    $correspondingStrategies = Arr::where($strategiesList, function ($strategy) use ($strategyNamesToRemove) {
        $strategyName = is_string($strategy) ? $strategy : $strategy[0];

        return in_array($strategyName, $strategyNamesToRemove);
    });

    foreach ($correspondingStrategies as $key => $value) {
        unset($strategiesList[$key]);
    }

    return $strategiesList;
}

/**
 * Add a strategy with its settings to a list of strategies, replacing the
 * strategy's existing entry if there is one.
 *
 * @param array $configurationTuple Tuple of [strategyName, settingsArray].
 *                                  Every strategy supports the "only" and "except" settings, to apply it to specific endpoints.
 *                                  Strategy::wrapWithSettings(only: [], except: []) builds the tuple.
 */
function configureStrategy(array $strategiesList, array $configurationTuple): array
{
    $strategyFound = false;
    $strategiesList = array_map(function ($strategy) use ($configurationTuple, &$strategyFound) {
        $strategyName = is_string($strategy) ? $strategy : $strategy[0];
        if ($strategyName === $configurationTuple[0]) {
            $strategyFound = true;

            return $configurationTuple;
        }

        return $strategy;
    }, $strategiesList);

    if (! $strategyFound) {
        $strategiesList = array_merge($strategiesList, [$configurationTuple]);
    }

    return $strategiesList;
}
