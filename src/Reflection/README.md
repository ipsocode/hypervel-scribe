# `Ipsocode\Scribe\Reflection`

A copy of [`mpociot/reflection-docblock`](https://github.com/mpociot/reflection-docblock),
itself a fork of phpDocumentor's ReflectionDocBlock 2.x, re-namespaced from
`Mpociot\Reflection` to `Ipsocode\Scribe\Reflection`.

It is bundled rather than required because the original package is
unmaintained and its signatures (`Context $context = null` and the like) trigger
PHP 8.4's implicit-nullable deprecation. The explicit nullable types are applied
to the sources here.

The namespace differs so this copy cannot collide with a real
`mpociot/reflection-docblock` loaded by the same autoloader.

Licensed MIT, copyright Mike van Riel; see [`LICENSE`](LICENSE). Local edits
stay minimal.
