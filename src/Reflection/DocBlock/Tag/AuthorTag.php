<?php

declare(strict_types=1);
/**
 * phpDocumentor.
 *
 * PHP Version 5.3
 *
 * @copyright 2010-2011 Mike van Riel / Naenius (http://www.naenius.com)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      http://phpdoc.org
 */

namespace Ipsocode\Scribe\Reflection\DocBlock\Tag;

use Ipsocode\Scribe\Reflection\DocBlock\Tag;

/**
 * Reflection class for an @author tag in a Docblock.
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link    http://phpdoc.org
 */
class AuthorTag extends Tag
{
    /**
     * PCRE regular expression matching any valid value for the name component.
     */
    public const REGEX_AUTHOR_NAME = '[^\<]*';

    /**
     * PCRE regular expression matching any valid value for the email component.
     */
    public const REGEX_AUTHOR_EMAIL = '[^\>]*';

    /** @var string The name of the author */
    protected $authorName = '';

    /** @var string The email of the author */
    protected $authorEmail = '';

    public function getContent()
    {
        if ($this->content === null) {
            $this->content = $this->authorName;
            if ($this->authorEmail != '') {
                $this->content .= "<{$this->authorEmail}>";
            }
        }

        return $this->content;
    }

    public function setContent($content)
    {
        parent::setContent($content);
        if (preg_match(
            '/^(' . self::REGEX_AUTHOR_NAME
            . ')(\<(' . self::REGEX_AUTHOR_EMAIL
            . ')\>)?$/u',
            $this->description,
            $matches
        )) {
            $this->authorName = trim($matches[1]);
            if (isset($matches[3])) {
                $this->authorEmail = trim($matches[3]);
            }
        }

        return $this;
    }

    /**
     * Gets the author's name.
     *
     * @return string the author's name
     */
    public function getAuthorName()
    {
        return $this->authorName;
    }

    /**
     * Sets the author's name.
     *
     * @param string $authorName The new author name.
     *                           An invalid value will set an empty string.
     *
     * @return $this
     */
    public function setAuthorName($authorName)
    {
        $this->content = null;
        $this->authorName
            = preg_match('/^' . self::REGEX_AUTHOR_NAME . '$/u', $authorName)
            ? $authorName : '';

        return $this;
    }

    /**
     * Gets the author's email.
     *
     * @return string the author's email
     */
    public function getAuthorEmail()
    {
        return $this->authorEmail;
    }

    /**
     * Sets the author's email.
     *
     * @param string $authorEmail The new author email.
     *                            An invalid value will set an empty string.
     *
     * @return $this
     */
    public function setAuthorEmail($authorEmail)
    {
        $this->authorEmail
            = preg_match('/^' . self::REGEX_AUTHOR_EMAIL . '$/u', $authorEmail)
            ? $authorEmail : '';

        $this->content = null;
        return $this;
    }
}
