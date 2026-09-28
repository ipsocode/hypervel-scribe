<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Hypervel\Support\Str;
use Parsedown;

/**
 * Parsedown, plus an id on every heading and a record of the headings seen.
 *
 * The HTML theme builds its sidebar from the intro/auth Markdown as well as
 * from the endpoints, so the parser has to hand back the document outline —
 * Parsedown itself only returns the rendered HTML. Instances are single-use:
 * `$headings` accumulates across every `text()` call until it is reset.
 */
class MarkdownParser extends Parsedown
{
    /**
     * @var array<int,array{text: string, level: int, slug: string}>
     */
    public array $headings = [];

    protected function blockHeader($Line)
    {
        $block = parent::blockHeader($Line);
        if (isset($block['element']['name'])) {
            $text = $block['element']['text']
                ?? $block['element']['handler']['argument']
                ?? '';
            $text = is_string($text) ? $text : '';
            $level = (int) mb_trim($block['element']['name'], 'h');
            $slug = Str::slug($text);
            $block['element']['attributes']['id'] = $slug;
            $this->headings[] = [
                'text' => $text,
                'level' => $level,
                'slug' => $slug,
            ];
        }

        return $block;
    }
}
