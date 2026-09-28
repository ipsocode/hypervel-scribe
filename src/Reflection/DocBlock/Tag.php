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

namespace Ipsocode\Scribe\Reflection\DocBlock;

use Exception;
use InvalidArgumentException;
use Ipsocode\Scribe\Reflection\DocBlock;
use Reflector;

/**
 * Parses a tag definition for a DocBlock.
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link    http://phpdoc.org
 */
class Tag implements Reflector
{
    /**
     * PCRE regular expression matching a tag name.
     */
    public const REGEX_TAGNAME = '[\w\-\_\\\]+';

    /** @var string Name of the tag */
    protected $tag = '';

    /**
     * @var null|string Content of the tag.
     *                  When set to NULL, it means it needs to be regenerated.
     */
    protected $content = '';

    /** @var string Description of the content of this tag */
    protected $description = '';

    /**
     * @var null|array The description, as an array of strings and Tag objects.
     *                 When set to NULL, it means it needs to be regenerated.
     */
    protected $parsedDescription;

    /** @var Location Location of the tag. */
    protected $location;

    /** @var DocBlock The DocBlock which this tag belongs to. */
    protected $docblock;

    /**
     * Default tag-to-handler mapping, restored by flushState(). Kept as a
     * const so a host app's registerTagHandler() call doesn't leak a handler
     * into every later run on the same worker.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_TAG_HANDLER_MAPPINGS = [
        'author' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\AuthorTag',
        'covers' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\CoversTag',
        'deprecated' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\DeprecatedTag',
        'example' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\ExampleTag',
        'link' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\LinkTag',
        'method' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\MethodTag',
        'param' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\ParamTag',
        'property-read' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\PropertyReadTag',
        'property' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\PropertyTag',
        'property-write' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\PropertyWriteTag',
        'return' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\ReturnTag',
        'see' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\SeeTag',
        'since' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\SinceTag',
        'source' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\SourceTag',
        'throw' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\ThrowsTag',
        'throws' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\ThrowsTag',
        'uses' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\UsesTag',
        'var' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\VarTag',
        'version' => '\Ipsocode\Scribe\Reflection\DocBlock\Tag\VersionTag',
    ];

    /**
     * @var array An array with a tag as a key, and an FQCN to a class that
     *            handles it as an array value. The class is expected to inherit this
     *            class.
     */
    private static $tagHandlerMappings = self::DEFAULT_TAG_HANDLER_MAPPINGS;

    /**
     * Restore the tag-handler mapping to its shipped defaults, discarding any
     * handlers registered via registerTagHandler() during the worker's life.
     */
    public static function flushState(): void
    {
        self::$tagHandlerMappings = self::DEFAULT_TAG_HANDLER_MAPPINGS;
    }

    /**
     * Factory method responsible for instantiating the correct sub type.
     *
     * @param string $tag_line the text for this tag, including description
     * @param DocBlock $docblock the DocBlock which this tag belongs to
     * @param Location $location location of the tag
     *
     * @return static a new tag object
     * @throws InvalidArgumentException if an invalid tag line was presented
     */
    final public static function createInstance(
        $tag_line,
        ?DocBlock $docblock = null,
        ?Location $location = null
    ) {
        if (! preg_match(
            '/^@(' . self::REGEX_TAGNAME . ')(?:\s*([^\s].*)|$)?/us',
            $tag_line,
            $matches
        )) {
            throw new InvalidArgumentException(
                'Invalid tag_line detected: ' . $tag_line
            );
        }

        $handler = __CLASS__;
        if (isset(self::$tagHandlerMappings[$matches[1]])) {
            $handler = self::$tagHandlerMappings[$matches[1]];
        } elseif (isset($docblock)) {
            $tagName = (string) new Type\Collection(
                [$matches[1]],
                $docblock->getContext()
            );

            if (isset(self::$tagHandlerMappings[$tagName])) {
                $handler = self::$tagHandlerMappings[$tagName];
            }
        }

        return new $handler(
            $matches[1],
            isset($matches[2]) ? $matches[2] : '',
            $docblock,
            $location
        );
    }

    /**
     * Registers a handler for tags.
     *
     * Registers a handler for tags. The class specified is autoloaded if it's
     * not available. It must inherit from this class.
     *
     * @param string $tag Name of tag to regiser a handler for. When
     *                    registering a namespaced tag, the full name, along with a prefixing
     *                    slash MUST be provided.
     * @param null|string $handler FQCN of handler. Specifing NULL removes the
     *                             handler for the specified tag, if any.
     *
     * @return bool TRUE on success, FALSE on failure
     */
    final public static function registerTagHandler($tag, $handler)
    {
        $tag = trim((string) $tag);

        if ($handler === null) {
            unset(self::$tagHandlerMappings[$tag]);
            return true;
        }

        if ($tag !== ''
            && class_exists($handler, true)
            && is_subclass_of($handler, __CLASS__)
            && ! strpos($tag, '\\') // Accept no slash, and 1st slash at offset 0.
        ) {
            self::$tagHandlerMappings[$tag] = $handler;
            return true;
        }

        return false;
    }

    /**
     * Parses a tag and populates the member variables.
     *
     * @param string $name name of the tag
     * @param string $content the contents of the given tag
     * @param DocBlock $docblock the DocBlock which this tag belongs to
     * @param Location $location location of the tag
     */
    public function __construct(
        $name,
        $content,
        ?DocBlock $docblock = null,
        ?Location $location = null
    ) {
        $this
            ->setName($name)
            ->setContent($content)
            ->setDocBlock($docblock)
            ->setLocation($location);
    }

    /**
     * Gets the name of this tag.
     *
     * @return string the name of this tag
     */
    public function getName()
    {
        return $this->tag;
    }

    /**
     * Sets the name of this tag.
     *
     * @param string $name the new name of this tag
     *
     * @return $this
     * @throws InvalidArgumentException when an invalid tag name is provided
     */
    public function setName($name)
    {
        if (! preg_match('/^' . self::REGEX_TAGNAME . '$/u', $name)) {
            throw new InvalidArgumentException(
                'Invalid tag name supplied: ' . $name
            );
        }

        $this->tag = $name;

        return $this;
    }

    /**
     * Gets the content of this tag.
     *
     * @return string
     */
    public function getContent()
    {
        if ($this->content === null) {
            $this->content = $this->description;
        }

        return $this->content;
    }

    /**
     * Sets the content of this tag.
     *
     * @param string $content the new content of this tag
     *
     * @return $this
     */
    public function setContent($content)
    {
        $this->setDescription($content);
        $this->content = $content;

        return $this;
    }

    /**
     * Gets the description component of this tag.
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Sets the description component of this tag.
     *
     * @param string $description the new description component of this tag
     *
     * @return $this
     */
    public function setDescription($description)
    {
        $this->content = null;
        $this->parsedDescription = null;
        $this->description = trim($description);

        return $this;
    }

    /**
     * Gets the parsed text of this description.
     *
     * @return array an array of strings and tag objects, in the order they
     *               occur within the description
     */
    public function getParsedDescription()
    {
        if ($this->parsedDescription === null) {
            $description = new Description($this->description, $this->docblock);
            $this->parsedDescription = $description->getParsedContents();
        }
        return $this->parsedDescription;
    }

    /**
     * Gets the docblock this tag belongs to.
     *
     * @return DocBlock the docblock this tag belongs to
     */
    public function getDocBlock()
    {
        return $this->docblock;
    }

    /**
     * Sets the docblock this tag belongs to.
     *
     * @param DocBlock $docblock The new docblock this tag belongs to. Setting
     *                           NULL removes any association.
     *
     * @return $this
     */
    public function setDocBlock(?DocBlock $docblock = null)
    {
        $this->docblock = $docblock;

        return $this;
    }

    /**
     * Gets the location of the tag.
     *
     * @return Location the tag's location
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
     * Sets the location of the tag.
     *
     * @param Location $location the new location of the tag
     *
     * @return $this
     */
    public function setLocation(?Location $location = null)
    {
        $this->location = $location;

        return $this;
    }

    /**
     * Builds a string representation of this object.
     *
     * @todo determine the exact format as used by PHP Reflection and implement it.
     *
     * @codeCoverageIgnore Not yet implemented
     */
    public static function export()
    {
        throw new Exception('Not yet implemented');
    }

    /**
     * Returns the tag as a serialized string.
     *
     * @return string
     */
    public function __toString()
    {
        return "@{$this->getName()} {$this->getContent()}";
    }
}
