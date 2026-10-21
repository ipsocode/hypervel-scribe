<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared;

use InvalidArgumentException;
use stdClass;

class ResponseFileTools
{
    public static function getResponseContents($filePath, array|string|null $merge): string
    {
        $content = self::getFileContents($filePath);
        if (empty($merge)) {
            return $content;
        }

        // Decoded to objects, so an empty `{}` is not turned into `[]` on the way back.
        $fileData = json_decode($content);
        if (is_string($merge)) {
            // Strict JSON first; single quotes stand in for double ones only
            // when that fails, so an apostrophe inside a value survives.
            $mergeData = json_decode($merge);
            if (! $mergeData instanceof stdClass) {
                $mergeData = json_decode(str_replace("'", '"', $merge));
            }
        } else {
            $mergeData = (object) $merge;
        }

        if (! $fileData instanceof stdClass || ! $mergeData instanceof stdClass) {
            throw new InvalidArgumentException("@responseFile {$filePath} and the JSON merged into it must both be JSON objects");
        }

        // Assigned key by key: array_merge() would renumber numeric keys such as "1".
        foreach (get_object_vars($mergeData) as $key => $value) {
            $fileData->{$key} = $value;
        }

        return json_encode($fileData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected static function getFileContents($filePath): string
    {
        if (! file_exists($filePath)) {
            // Fall back to a path relative to the storage directory
            if (! file_exists(storage_path($filePath))) {
                throw new InvalidArgumentException("@responseFile {$filePath} does not exist");
            }

            $filePath = storage_path($filePath);
        }

        return file_get_contents($filePath, true);
    }
}
