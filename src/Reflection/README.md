# `Ipsocode\Scribe\Reflection`

A vendored copy of [`mpociot/reflection-docblock`](https://github.com/mpociot/reflection-docblock)
(itself a fork of `phpdocumentor/reflection-docblock` 2.x), re-namespaced from
`Mpociot\Reflection` to `Ipsocode\Scribe\Reflection`.

It is bundled rather than required from Packagist because the upstream package is
unmaintained and its 2.x-era signatures (`Context $context = null`, etc.) trigger
PHP 8.4's implicit-nullable deprecation. Requiring it would take a
`cweagans/composer-patches` patch in this package *and* a duplicate patch plus a
`patches-ignore` entry in every consuming application. The nullable types are
applied directly to the sources here instead.

The namespace is deliberately changed so this copy cannot collide with a real
`mpociot/reflection-docblock` installed elsewhere in the same autoloader.

Licensed MIT (see `LICENSE`); copyright Mike van Riel. Keep local edits to a
minimum so upstream changes stay easy to diff in.
