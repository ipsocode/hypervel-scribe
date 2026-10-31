# Differences from upstream

hypervel-scribe is a port of [`knuckleswtf/scribe`](https://github.com/knuckleswtf/scribe), and it
keeps upstream's behavior except where this page says otherwise.

## An MCP server for the generated docs

The package can serve the generated docs to AI clients over MCP, which `knuckleswtf/scribe` does
not do. It lives in the `scribe.mcp` config group and the `Ipsocode\Scribe\Mcp\` classes, and it
is off unless you enable it. See [MCP server](mcp.md).
