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

/**
 * The context in which a DocBlock occurs.
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link    http://phpdoc.org
 */
class Context
{
    /** @var string The current namespace. */
    protected $namespace = '';

    /** @var array List of namespace aliases => Fully Qualified Namespace. */
    protected $namespace_aliases = [];

    /** @var string Name of the structural element, within the namespace. */
    protected $lsen = '';

    /**
     * Cteates a new context.
     * @param string $namespace the namespace where this DocBlock
     *                          resides in
     * @param array $namespace_aliases list of namespace aliases => Fully
     *                                 Qualified Namespace
     * @param string $lsen name of the structural element, within
     *                     the namespace
     */
    public function __construct(
        $namespace = '',
        array $namespace_aliases = [],
        $lsen = ''
    ) {
        if (! empty($namespace)) {
            $this->setNamespace($namespace);
        }
        $this->setNamespaceAliases($namespace_aliases);
        $this->setLSEN($lsen);
    }

    /**
     * @return string the namespace where this DocBlock resides in
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @return array list of namespace aliases => Fully Qualified Namespace
     */
    public function getNamespaceAliases()
    {
        return $this->namespace_aliases;
    }

    /**
     * Returns the Local Structural Element Name.
     *
     * @return string name of the structural element, within the namespace
     */
    public function getLSEN()
    {
        return $this->lsen;
    }

    /**
     * Sets a new namespace.
     *
     * Sets a new namespace for the context. Leading and trailing slashes are
     * trimmed, and the keywords "global" and "default" are treated as aliases
     * to no namespace.
     *
     * @param string $namespace the new namespace to set
     *
     * @return $this
     */
    public function setNamespace($namespace)
    {
        if ($namespace !== 'global'
            && $namespace !== 'default'
        ) {
            // Srip leading and trailing slash
            $this->namespace = trim((string) $namespace, '\\');
        } else {
            $this->namespace = '';
        }
        return $this;
    }

    /**
     * Sets the namespace aliases, replacing all previous ones.
     *
     * @param array $namespace_aliases list of namespace aliases => Fully
     *                                 Qualified Namespace
     *
     * @return $this
     */
    public function setNamespaceAliases(array $namespace_aliases)
    {
        $this->namespace_aliases = [];
        foreach ($namespace_aliases as $alias => $fqnn) {
            $this->setNamespaceAlias($alias, $fqnn);
        }
        return $this;
    }

    /**
     * Adds a namespace alias to the context.
     *
     * @param string $alias the alias name (the part after "as", or the last
     *                      part of the Fully Qualified Namespace Name) to add
     * @param string $fqnn The Fully Qualified Namespace Name for this alias.
     *                     Any form of leading/trailing slashes are accepted, but what will be
     *                     stored is a name, prefixed with a slash, and no trailing slash.
     *
     * @return $this
     */
    public function setNamespaceAlias($alias, $fqnn)
    {
        $this->namespace_aliases[$alias] = '\\' . trim((string) $fqnn, '\\');
        return $this;
    }

    /**
     * Sets a new Local Structural Element Name.
     *
     * Sets a new Local Structural Element Name. A local name also contains
     * punctuation determining the kind of structural element (e.g. trailing "("
     * and ")" for functions and methods).
     *
     * @param string $lsen the new local name of a structural element
     *
     * @return $this
     */
    public function setLSEN($lsen)
    {
        $this->lsen = (string) $lsen;
        return $this;
    }
}
