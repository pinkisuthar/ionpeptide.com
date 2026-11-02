# Translations for the Zorem MCP library

The library owns the `zorem-mcp` text domain. Drop `zorem-mcp-{locale}.mo`
files here and `bootstrap.php` loads the one matching the site's locale.

They live with the library, not with a host plugin, for the same reason the
code does: every Zorem plugin ships the same copy, and a string translated
once should read the same on all of them.
