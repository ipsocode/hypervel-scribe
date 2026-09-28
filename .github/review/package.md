This package, hypervel-scribe, is a port of knuckleswtf/scribe that generates API
documentation for Hypervel 0.4 (PHP 8.4+, Swoole).

In src/, in order:
1. What gets extracted from routes, docblocks, attributes, validation rules and
   responses, and the generated HTML page, OpenAPI spec and Postman collection.
2. Coroutine safety: scribe:generate can run in-process in a long-lived Swoole worker
   (Artisan::call, a queued RegenerateDocumentation). New static state whose
   flushState() is not reached from src/Testing/TestState.php (per-run caches through
   Tools\RunState::flush()); request-scoped state kept outside Hypervel\Context;
   blocking calls on that path.
3. src/Reflection/ is re-namespaced third-party code: flag a change to it unless the
   pull request is about it.
4. Security: unescaped values from docblocks, config or responses in the generated
   HTML, and what response calls send through the application.
