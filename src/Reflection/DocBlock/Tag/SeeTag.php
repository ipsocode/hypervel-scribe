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
 * Reflection class for a @see tag in a Docblock.
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link    http://phpdoc.org
 */
class SeeTag extends Tag
{
    /** @var string */
    protected $refers;

    public function getContent()
    {
        if ($this->content === null) {
            $this->content = "{$this->refers} {$this->description}";
        }
        return $this->content;
    }

    public function setContent($content)
    {
        parent::setContent($content);
        $parts = preg_split('/\s+/Su', $this->description, 2);

        // any output is considered a type
        $this->refers = $parts[0];

        $this->setDescription(isset($parts[1]) ? $parts[1] : '');

        $this->content = $content;
        return $this;
    }

    /**
     * Gets the structural element this tag refers to.
     *
     * @return string
     */
    public function getReference()
    {
        return $this->refers;
    }

    /**
     * Sets the structural element this tag refers to.
     *
     * @param string $refers the new type this tag refers to
     *
     * @return $this
     */
    public function setReference($refers)
    {
        $this->refers = $refers;

        $this->content = null;
        return $this;
    }
}
