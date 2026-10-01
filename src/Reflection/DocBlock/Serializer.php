<?php

declare(strict_types=1);
/**
 * phpDocumentor.
 *
 * PHP Version 5.3
 *
 * @copyright 2013 Mike van Riel / Naenius (http://www.naenius.com)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      http://phpdoc.org
 */

namespace Ipsocode\Scribe\Reflection\DocBlock;

use Ipsocode\Scribe\Reflection\DocBlock;

/**
 * Serializes a DocBlock instance.
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link    http://phpdoc.org
 */
class Serializer
{
    /** @var string The string to indent the comment with. */
    protected $indentString = ' ';

    /** @var int The number of times the indent string is repeated. */
    protected $indent = 0;

    /** @var bool Whether to indent the first line. */
    protected $isFirstLineIndented = true;

    /** @var null|int The max length of a line. */
    protected $lineLength;

    /**
     * Create a Serializer instance.
     *
     * @param int $indent the number of times the indent string is
     *                    repeated
     * @param string $indentString the string to indent the comment with
     * @param bool $indentFirstLine whether to indent the first line
     * @param null|int $lineLength the max length of a line or NULL to
     *                             disable line wrapping
     */
    public function __construct(
        $indent = 0,
        $indentString = ' ',
        $indentFirstLine = true,
        $lineLength = null
    ) {
        $this->setIndentationString($indentString);
        $this->setIndent($indent);
        $this->setIsFirstLineIndented($indentFirstLine);
        $this->setLineLength($lineLength);
    }

    /**
     * Sets the string to indent comments with.
     *
     * @param mixed $indentString
     *
     * @return $this this serializer object
     */
    public function setIndentationString($indentString)
    {
        $this->indentString = (string) $indentString;
        return $this;
    }

    /**
     * Gets the string to indent comments with.
     *
     * @return string the indent string
     */
    public function getIndentationString()
    {
        return $this->indentString;
    }

    /**
     * Sets the number of indents.
     *
     * @param int $indent the number of times the indent string is repeated
     *
     * @return $this this serializer object
     */
    public function setIndent($indent)
    {
        $this->indent = (int) $indent;
        return $this;
    }

    /**
     * Gets the number of indents.
     *
     * @return int the number of times the indent string is repeated
     */
    public function getIndent()
    {
        return $this->indent;
    }

    /**
     * Sets whether or not the first line should be indented.
     *
     * Sets whether or not the first line (the one with the "/**") should be
     * indented.
     *
     * @param bool $indentFirstLine the new value for this setting
     *
     * @return $this this serializer object
     */
    public function setIsFirstLineIndented($indentFirstLine)
    {
        $this->isFirstLineIndented = (bool) $indentFirstLine;
        return $this;
    }

    /**
     * Gets whether or not the first line should be indented.
     *
     * @return bool whether or not the first line should be indented
     */
    public function isFirstLineIndented()
    {
        return $this->isFirstLineIndented;
    }

    /**
     * Sets the line length.
     *
     * Sets the length of each line in the serialization. Content will be
     * wrapped within this limit.
     *
     * @param null|int $lineLength The length of each line. NULL to disable line
     *                             wrapping altogether.
     *
     * @return $this this serializer object
     */
    public function setLineLength($lineLength)
    {
        $this->lineLength = $lineLength === null ? null : (int) $lineLength;
        return $this;
    }

    /**
     * Gets the line length.
     *
     * @return null|int the length of each line or NULL if line wrapping is
     *                  disabled
     */
    public function getLineLength()
    {
        return $this->lineLength;
    }

    /**
     * Generate a DocBlock comment.
     *
     * @param DocBlock the DocBlock to serialize
     *
     * @return string the serialized doc block
     */
    public function getDocComment(DocBlock $docblock)
    {
        $indent = str_repeat($this->indentString, $this->indent);
        $firstIndent = $this->isFirstLineIndented ? $indent : '';

        $text = $docblock->getText();
        if ($this->lineLength) {
            // 3 === strlen(' * ')
            $wrapLength = $this->lineLength - strlen($indent) - 3;
            $text = wordwrap($text, $wrapLength);
        }
        $text = str_replace("\n", "\n{$indent} * ", $text);

        $comment = "{$firstIndent}/**\n{$indent} * {$text}\n{$indent} *\n";

        /** @var Tag $tag */
        foreach ($docblock->getTags() as $tag) {
            $tagText = (string) $tag;
            if ($this->lineLength) {
                $tagText = wordwrap($tagText, $wrapLength);
            }
            $tagText = str_replace("\n", "\n{$indent} * ", $tagText);

            $comment .= "{$indent} * {$tagText}\n";
        }

        $comment .= $indent . ' */';

        return $comment;
    }
}
