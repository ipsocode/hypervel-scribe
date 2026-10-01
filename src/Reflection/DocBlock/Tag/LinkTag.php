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
 * Reflection class for a @link tag in a Docblock.
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link    http://phpdoc.org
 */
class LinkTag extends Tag
{
    /** @var string */
    protected $link = '';

    public function getContent()
    {
        if ($this->content === null) {
            $this->content = "{$this->link} {$this->description}";
        }

        return $this->content;
    }

    public function setContent($content)
    {
        parent::setContent($content);
        $parts = preg_split('/\s+/Su', $this->description, 2);

        $this->link = $parts[0];

        $this->setDescription(isset($parts[1]) ? $parts[1] : $parts[0]);

        $this->content = $content;
        return $this;
    }

    /**
     * Gets the link.
     *
     * @return string
     */
    public function getLink()
    {
        return $this->link;
    }

    /**
     * Sets the link.
     *
     * @param string $link The link
     *
     * @return $this
     */
    public function setLink($link)
    {
        $this->link = $link;

        $this->content = null;
        return $this;
    }
}
