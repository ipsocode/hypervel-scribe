<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Hypervel\Support\Str;
use Symfony\Component\VarExporter\VarExporter;

/**
 * Report how one config array differs from another, as a flat map of dotted
 * paths to printable values.
 *
 * Backs `scribe:config-diff`, whose whole job is to turn a published
 * `config/scribe.php` into something short enough to paste into a bug report.
 * That is why the output is strings rather than values: the caller prints it,
 * it never round-trips back into config.
 */
class ConfigDiffer
{
    /**
     * @param array $original the baseline to compare against — the package's own defaults
     * @param array $changed the array being described, whose keys drive the walk
     * @param array $ignorePaths dotted paths to skip, as `Str::is()` patterns. For prose and
     *                           per-application lists, where "differs from the default" is the
     *                           normal state and reporting it is only noise.
     * @param array $asList dotted paths to compare as unordered lists rather than by key,
     *                      reported as `added x: removed y`
     */
    public function __construct(
        protected array $original,
        protected array $changed,
        protected array $ignorePaths = [],
        protected array $asList = [],
    ) {
    }

    /**
     * @return array<string, string> dotted path => printable description of the change
     */
    public function getDiff(): array
    {
        return $this->recursiveItemDiff($this->original, $this->changed);
    }

    /**
     * @return array<string, string>
     */
    protected function recursiveItemDiff(mixed $old, array $new, string $prefix = ''): array
    {
        $diff = [];

        foreach ($new as $key => $value) {
            $fullKey = $prefix . $key;
            if (Str::is($this->ignorePaths, $fullKey)) {
                continue;
            }

            $oldValue = data_get($old, $key);

            if (is_array($value)) {
                if (Str::is($this->asList, $fullKey)) {
                    $listDiff = $this->diffList($oldValue, $value);
                    if (! empty($listDiff)) {
                        $diff[$fullKey] = $listDiff;
                    }
                } else {
                    $diff = array_merge(
                        $diff,
                        $this->recursiveItemDiff($oldValue, $value, "{$fullKey}.")
                    );
                }
            } elseif ($oldValue !== $value) {
                $diff[$fullKey] = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $diff;
    }

    /**
     * Describe a list by what joined and left it, ignoring order.
     */
    protected function diffList(mixed $oldValue, array $value): string
    {
        if (! is_array($oldValue)) {
            return 'changed to a list';
        }

        $added = array_map(fn ($v) => "{$v}", $this->subtractArraysFlat($value, $oldValue));
        $removed = array_map(fn ($v) => "{$v}", $this->subtractArraysFlat($oldValue, $value));

        $diff = [];
        if (! empty($added)) {
            $diff[] = 'added ' . implode(', ', $added);
        }
        if (! empty($removed)) {
            $diff[] = 'removed ' . implode(', ', $removed);
        }

        return empty($diff) ? '' : implode(': ', $diff);
    }

    /**
     * Basically array_diff, but handling items which may also be arrays.
     *
     * `array_diff` compares items as strings, so a nested array in either list
     * would raise "Array to string conversion" and then compare equal to every
     * other array. Exporting them first gives each one a distinct, comparable
     * spelling — which `strategies.*` needs, since a configured strategy is an
     * array rather than a class-string.
     *
     * @return array<int|string, string>
     */
    protected function subtractArraysFlat(array $a, array $b): array
    {
        $export = fn ($item) => is_array($item) ? VarExporter::export($item) : $item;

        return array_diff(array_map($export, $a), array_map($export, $b));
    }
}
