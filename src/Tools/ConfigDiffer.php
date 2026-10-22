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
     * Keys whose string values are credentials, wherever they sit: a header in
     * a strategy's settings as much as a top-level option.
     */
    protected const string SENSITIVE_KEY = '/authorization|token|secret|password|passwd|api[-_]?key|cookie|credential/i';

    protected const string REDACTED = '<redacted>';

    /**
     * @param array $original the baseline to compare against — the package's own defaults
     * @param array $changed the array being described, whose keys drive the walk
     * @param array $ignorePaths dotted paths to skip, as `Str::is()` patterns. For prose and
     *                           per-application lists, where "differs from the default" is the
     *                           normal state and reporting it is only noise.
     * @param array $asList dotted paths to compare as unordered lists rather than by key,
     *                      reported as `added x: removed y`
     * @param string[] $redactPaths dotted paths, as `Str::is()` patterns, whose values are reported
     *                              as changed but never printed, besides any key matching
     *                              SENSITIVE_KEY. The output is meant for a public bug report.
     */
    public function __construct(
        protected array $original,
        protected array $changed,
        protected array $ignorePaths = [],
        protected array $asList = [],
        protected array $redactPaths = [],
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
                $printable = Str::is($this->redactPaths, $fullKey) ? $this->redactValue($value) : $this->redact([$key => $value])[$key];
                $diff[$fullKey] = json_encode($printable, JSON_UNESCAPED_SLASHES);
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
     * array_diff() for lists whose items may themselves be arrays.
     *
     * `array_diff` compares items as strings, so a nested array in either list
     * would raise "Array to string conversion" and then compare equal to every
     * other array. Exporting them first gives each one a distinct, comparable
     * spelling — which `strategies.*` needs, since a configured strategy is an
     * array rather than a class-string.
     *
     * The comparison uses the real values, so a rotated credential still counts
     * as a change; only what is returned for printing is redacted.
     *
     * @return array<int|string, string>
     */
    protected function subtractArraysFlat(array $a, array $b): array
    {
        $export = fn ($item) => is_array($item) ? VarExporter::export($item) : $item;
        $missing = array_diff(array_map($export, $a), array_map($export, $b));

        $printable = [];
        foreach (array_keys($missing) as $key) {
            $printable[$key] = is_array($a[$key]) ? VarExporter::export($this->redact($a[$key])) : $a[$key];
        }

        return $printable;
    }

    /**
     * The array with the value under every SENSITIVE_KEY replaced, at any depth.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    protected function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->redact($value);
            } elseif (preg_match(self::SENSITIVE_KEY, (string) $key)) {
                $values[$key] = $this->redactValue($value);
            }
        }

        return $values;
    }

    /**
     * A credential's stand-in. An empty or non-string value reveals nothing,
     * and printing it keeps "unset" and "turned off" readable.
     */
    protected function redactValue(mixed $value): mixed
    {
        return is_string($value) && $value !== '' ? self::REDACTED : $value;
    }
}
