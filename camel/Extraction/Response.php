<?php

declare(strict_types=1);

namespace Ipsocode\Camel\Extraction;

use Hypervel\Support\Str;
use Ipsocode\Camel\BaseDTO;

class Response extends BaseDTO
{
    public int $status;

    public ?string $content;

    public array $headers = [];

    public ?string $description;

    public function __construct(array $parameters = [])
    {
        if (is_array($parameters['content'] ?? null)) {
            $parameters['content'] = json_encode($parameters['content'], JSON_UNESCAPED_SLASHES);
        }

        if (isset($parameters['status'])) {
            $parameters['status'] = (int) $parameters['status'];
        }

        // Headers that change on every call, and the cookies a response sets: a
        // session, XSRF or remember-me cookie is valid against the environment
        // the docs were generated in, and the docs are public. Header names are
        // case-insensitive, and YAML written by an earlier version may hold any case.
        $hiddenHeaders = [
            'date',
            'etag',
            'last-modified',
            'content-length',
            'connection',
            'x-powered-by',
            'set-cookie',
        ];
        if (! empty($parameters['headers'])) {
            $parameters['headers'] = array_filter(
                $parameters['headers'],
                fn ($name) => ! in_array(strtolower((string) $name), $hiddenHeaders, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        parent::__construct($parameters);
    }

    public function fullDescription()
    {
        $description = $this->status;
        if ($this->description) {
            $description .= ", {$this->description}";
        }

        return $description;
    }

    public function isBinary(): bool
    {
        return is_string($this->content) && Str::startsWith($this->content, '<<binary>>');
    }
}
